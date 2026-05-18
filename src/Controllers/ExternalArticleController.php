<?php

namespace ArticleList4711\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Item\Variation\Contracts\VariationSearchRepositoryContract;
use Plenty\Modules\Item\SalesPrice\Contracts\SalesPriceRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

class ExternalArticleController extends Controller
{
    /** Schema-Version des Response-Envelopes. Erhöhen, wenn sich das Format inkompatibel ändert. */
    const SCHEMA_VERSION = '1';

    const DEFAULT_PER_PAGE = 50;
    const MAX_PER_PAGE     = 200;

    /**
     * Per-Request-Cache: salesPriceId => currency-String.
     * Wird in loadArticlePage() vor dem Serialize befüllt und von
     * serializePrice() konsumiert. Reset bei jedem Request am Anfang
     * von loadArticlePage(), damit kein Cross-Request-Leak entsteht.
     */
    private static $currencyMap = [];

    /**
     * Eager-Load-Liste für den Item-Load (Schritt 1).
     * Variations werden separat in Schritt 2 via VariationSearchRepositoryContract
     * geladen, weil der Item-Repo-Resolver für viele Sub-Relations (Preise,
     * Properties, Markets) ignoriert eager wird.
     */
    private static function itemRelations(): array
    {
        return ['texts', 'itemImages'];
    }

    /**
     * Eager-Load-Map für den Variation-Load (Schritt 2).
     * VariationSearchRepositoryContract erwartet die with-Map als
     * assoziatives Array (Key=Relation, Value=null|sub-with-spec).
     */
    private static function variationRelations(): array
    {
        return [
            'variationSalesPrices'     => null,
            'variationProperties'      => null,
            'variationBarcodes'        => null,
            'variationCategories'      => null,
            'variationClients'         => null,
            'variationMarkets'         => null,
            'variationAttributeValues' => null,
            'unit'                     => null,
            'stock'                    => null,
        ];
    }

    public function index(
        Request $request,
        Response $response,
        ConfigRepository $config,
        ItemRepositoryContract $itemRepository,
        VariationSearchRepositoryContract $variationRepository,
        SalesPriceRepositoryContract $salesPriceRepository,
        AuthHelper $authHelper
    ) {
        $authErr = self::requireValidApiKey($request, $response, $config);
        if ($authErr !== null) return $authErr;

        list($page, $perPage, $lang) = self::parsePagination($request);
        $loaded = self::loadArticlePage($authHelper, $itemRepository, $variationRepository, $salesPriceRepository, $page, $perPage, $lang);

        return $response->json([
            'data'       => $loaded['articles'],
            'pagination' => $loaded['pagination'],
            'meta'       => self::baseMeta($request) + [
                'lang'                 => $lang,
                'with_item'            => self::itemRelations(),
                'with_variation'       => array_keys(self::variationRelations()),
                'schema_version'       => self::SCHEMA_VERSION,
            ],
        ], 200);
    }

    /**
     * Externer Endpoint, gefiltert nach Plenty-`storeSpecial`-Markierung.
     * storeSpecial ist eine Item-Eigenschaft (0=keine, 1-5=Plenty-Standardwerte
     * wie Schnäppchen/Neu/Top-Artikel/etc.). Routen-Param: beliebige int.
     *
     * Filter wird im PHP nach dem Plenty-Load angewandt — `pagination.total_count`
     * bleibt die ungefilterte Plenty-Summe; `pagination.returned_count` und das
     * `filter`-Feld zeigen das gefilterte Ergebnis. Für vollständigen Filter-Sync
     * den Loop bis `has_next_page == false` laufen lassen und client-seitig
     * sammeln.
     */
    public function byMarking(
        Request $request,
        Response $response,
        ConfigRepository $config,
        ItemRepositoryContract $itemRepository,
        VariationSearchRepositoryContract $variationRepository,
        SalesPriceRepositoryContract $salesPriceRepository,
        AuthHelper $authHelper,
        int $storeSpecial
    ) {
        $authErr = self::requireValidApiKey($request, $response, $config);
        if ($authErr !== null) return $authErr;

        list($page, $perPage, $lang) = self::parsePagination($request);
        $loaded = self::loadArticlePage($authHelper, $itemRepository, $variationRepository, $salesPriceRepository, $page, $perPage, $lang);

        $filtered = [];
        foreach ($loaded['articles'] as $article) {
            if ((int) $article['store_special'] === $storeSpecial) {
                $filtered[] = $article;
            }
        }

        $pagination = $loaded['pagination'];
        $pagination['returned_count'] = count($filtered);

        return $response->json([
            'data'       => $filtered,
            'pagination' => $pagination,
            'filter'     => [
                'store_special' => $storeSpecial,
            ],
            'meta'       => self::baseMeta($request) + [
                'lang'                 => $lang,
                'with_item'            => self::itemRelations(),
                'with_variation'       => array_keys(self::variationRelations()),
                'filter_applied'       => 'post_load_php',
                'schema_version'       => self::SCHEMA_VERSION,
            ],
        ], 200);
    }

    private static function requireValidApiKey(Request $request, Response $response, ConfigRepository $config)
    {
        $expected = (string) $config->get('ArticleList4711.external_api_key', '');
        $provided = (string) $request->header('X-Api-Key');

        if ($expected === '' || $provided === '' || $provided !== $expected) {
            return $response->json([
                'error' => [
                    'code'    => 'unauthorized',
                    'message' => 'Missing or invalid X-Api-Key header.',
                ],
                'meta' => self::baseMeta($request),
            ], 401);
        }
        return null;
    }

    private static function parsePagination(Request $request): array
    {
        $page    = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', self::DEFAULT_PER_PAGE);
        if ($page    < 1) $page    = 1;
        if ($perPage < 1) $perPage = self::DEFAULT_PER_PAGE;
        if ($perPage > self::MAX_PER_PAGE) $perPage = self::MAX_PER_PAGE;

        $lang = (string) $request->get('lang', 'de');
        if ($lang === '') $lang = 'de';

        return [$page, $perPage, $lang];
    }

    private static function loadArticlePage(
        AuthHelper $authHelper,
        ItemRepositoryContract $itemRepository,
        VariationSearchRepositoryContract $variationRepository,
        SalesPriceRepositoryContract $salesPriceRepository,
        int $page,
        int $perPage,
        string $lang
    ): array {
        // Per-Request-Reset des Currency-Caches.
        self::$currencyMap = [];

        // Schritt 1: Items paginieren (Item-Pagination bleibt der Konsumenten-Vertrag).
        $paginated = $authHelper->processUnguarded(function () use ($itemRepository, $page, $perPage, $lang) {
            return $itemRepository->search([], [$lang], $page, $perPage, self::itemRelations());
        });

        $entries = $paginated->getResult();

        $items   = [];
        $itemIds = [];
        if (is_array($entries) || is_object($entries)) {
            foreach ($entries as $item) {
                $items[] = $item;
                $id = self::asInt(self::pick($item, 'id'));
                if ($id !== null) $itemIds[] = $id;
            }
        }

        // Schritt 2: Variations + Sub-Relations für genau diese Item-IDs holen.
        $variationsByItemId = self::loadVariationsByItemId($authHelper, $variationRepository, $itemIds);

        // Schritt 2b: Currency pro salesPriceId vorladen (Plenty's VariationSalesPrice
        // hat keine eigene currency — die haengt am SalesPrice-Parent).
        self::$currencyMap = self::loadCurrencyMap($authHelper, $salesPriceRepository, $variationsByItemId);

        // Schritt 3: serialize, jeweils mit den passenden Varianten angereichert.
        $articles = [];
        foreach ($items as $item) {
            $itemId = self::asInt(self::pick($item, 'id'));
            $vars   = ($itemId !== null && isset($variationsByItemId[$itemId])) ? $variationsByItemId[$itemId] : [];
            $articles[] = self::serializeArticle($item, $lang, $vars);
        }

        $totalCount  = null;
        $isLastPage  = null;
        $lastPageNum = null;
        $paginatedArr = $paginated->toArray();
        if (is_array($paginatedArr)) {
            if (isset($paginatedArr['totalsCount']))    $totalCount  = (int) $paginatedArr['totalsCount'];
            if (isset($paginatedArr['isLastPage']))     $isLastPage  = (bool) $paginatedArr['isLastPage'];
            if (isset($paginatedArr['lastPageNumber'])) $lastPageNum = (int) $paginatedArr['lastPageNumber'];
        }

        return [
            'articles'   => $articles,
            'pagination' => [
                'page'           => $page,
                'per_page'       => $perPage,
                'returned_count' => count($articles),
                'total_count'    => $totalCount,
                'last_page'      => $lastPageNum,
                'is_last_page'   => $isLastPage,
                'has_next_page'  => $isLastPage === null ? null : ! $isLastPage,
            ],
        ];
    }

    /**
     * Sammelt einzigartige salesPriceIds aus den geladenen Variations, lädt
     * die zugehörigen SalesPrice-Parents via SalesPriceRepositoryContract und
     * liefert eine Map id => currency-String.
     *
     * Plenty's VariationSalesPrice hat keine eigene currency — die haengt am
     * SalesPrice-Parent (typisch wenige SalesPrice-Konfigurationen pro Shop,
     * also N kleine findById-Lookups; nicht eskalierend mit der Item-Anzahl).
     */
    private static function loadCurrencyMap(
        AuthHelper $authHelper,
        SalesPriceRepositoryContract $salesPriceRepository,
        array $variationsByItemId
    ): array {
        $uniqueIds = [];
        foreach ($variationsByItemId as $variationList) {
            foreach ($variationList as $v) {
                $prices = self::prop($v, 'variationSalesPrices');
                if (!is_array($prices) && !is_object($prices)) continue;
                foreach ($prices as $p) {
                    $id = self::asInt(self::prop($p, 'salesPriceId'));
                    if ($id !== null) $uniqueIds[$id] = true;
                }
            }
        }
        if (empty($uniqueIds)) {
            return [];
        }

        $rawSalesPrices = $authHelper->processUnguarded(function () use ($salesPriceRepository, $uniqueIds) {
            $raw = [];
            foreach (array_keys($uniqueIds) as $id) {
                $sp = $salesPriceRepository->findById($id);
                if ($sp !== null) {
                    $raw[$id] = $sp;
                }
            }
            return $raw;
        });

        $byId = [];
        if (is_array($rawSalesPrices)) {
            foreach ($rawSalesPrices as $id => $sp) {
                $currency = self::prop($sp, 'currency');
                if (is_string($currency) && $currency !== '') {
                    $byId[(int) $id] = $currency;
                }
            }
        }
        return $byId;
    }

    /**
     * Lädt alle Varianten für eine Liste von Item-IDs und gruppiert sie nach itemId.
     * Genau ein Variation-Repo-Call pro Item-Seite — Sub-Relations werden zuverlässig
     * eager geladen, weil der VariationSearchRepository-Resolver mehr Relations
     * unterstützt als ItemRepositoryContract::search().
     */
    private static function loadVariationsByItemId(
        AuthHelper $authHelper,
        VariationSearchRepositoryContract $variationRepository,
        array $itemIds
    ): array {
        if (empty($itemIds)) {
            return [];
        }

        $rawVariations = $authHelper->processUnguarded(function () use ($variationRepository, $itemIds) {
            $variationRepository->setSearchParams([
                'with' => self::variationRelations(),
            ]);
            $result = $variationRepository->search(['itemIds' => $itemIds]);
            return $result->getResult();
        });

        $byItemId = [];
        if (is_array($rawVariations) || is_object($rawVariations)) {
            foreach ($rawVariations as $v) {
                $itemId = self::asInt(self::prop($v, 'itemId'));
                if ($itemId === null) continue;
                if (!isset($byItemId[$itemId])) {
                    $byItemId[$itemId] = [];
                }
                $byItemId[$itemId][] = $v;
            }
        }
        return $byItemId;
    }

    /**
     * Wandelt ein Plenty-Item (Array oder Objekt) in das Export-Schema.
     * Sandbox-konform: nur is_*, isset, get_class, kein method_exists,
     * keine dynamischen Property-Namen.
     */
    private static function serializeArticle($item, string $lang, array $preloadedVariations = []): array
    {
        return [
            'id'               => self::asInt(self::pick($item, 'id')),
            'position'         => self::asInt(self::pick($item, 'position')),
            'manufacturer_id'  => self::asInt(self::pick($item, 'manufacturerId')),
            'stock_limitation' => self::asInt(self::pick($item, 'stockLimitation')),
            'store_special'    => self::asInt(self::pick($item, 'storeSpecial')),
            'created_at'       => self::asIsoDate(self::pick($item, 'createdAt')),
            'updated_at'       => self::asIsoDate(self::pick($item, 'updatedAt')),
            'texts_by_lang'    => self::extractTextsByLang($item),
            'primary_name'     => self::primaryName($item, $lang),
            'images'           => self::extractImages($item),
            'variations'       => self::serializeVariationList($preloadedVariations),
        ];
    }

    private static function pick($item, string $key)
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }
        if (is_object($item)) {
            // statisch erlaubte Property-Zugriffe für die bekannten Felder
            switch ($key) {
                case 'id':              return isset($item->id)              ? $item->id              : null;
                case 'position':        return isset($item->position)        ? $item->position        : null;
                case 'manufacturerId':  return isset($item->manufacturerId)  ? $item->manufacturerId  : null;
                case 'stockLimitation': return isset($item->stockLimitation) ? $item->stockLimitation : null;
                case 'storeSpecial':    return isset($item->storeSpecial)    ? $item->storeSpecial    : null;
                case 'createdAt':       return isset($item->createdAt)       ? $item->createdAt       : null;
                case 'updatedAt':       return isset($item->updatedAt)       ? $item->updatedAt       : null;
                case 'texts':           return isset($item->texts)           ? $item->texts           : null;
                case 'itemImages':      return isset($item->itemImages)      ? $item->itemImages      : null;
            }
        }
        return null;
    }

    private static function extractTextsByLang($item): array
    {
        $texts = self::pick($item, 'texts');
        if ($texts === null || (!is_array($texts) && !is_object($texts))) {
            return [];
        }

        $out = [];
        foreach ($texts as $t) {
            $lang = self::textField($t, 'lang');
            if (!is_string($lang) || $lang === '') {
                continue;
            }
            $out[$lang] = [
                'name1'             => self::asString(self::textField($t, 'name1')),
                'name2'             => self::asString(self::textField($t, 'name2')),
                'name3'             => self::asString(self::textField($t, 'name3')),
                'description'       => self::asString(self::textField($t, 'description')),
                'short_description' => self::asString(self::textField($t, 'shortDescription')),
                'technical_data'    => self::asString(self::textField($t, 'technicalData')),
                'meta_keywords'     => self::asString(self::textField($t, 'keywords')),
                'meta_description'  => self::asString(self::textField($t, 'metaDescription')),
                'url_path'          => self::asString(self::textField($t, 'urlPath')),
            ];
        }
        return $out;
    }

    private static function textField($t, string $key)
    {
        if (is_array($t)) {
            return $t[$key] ?? null;
        }
        if (is_object($t)) {
            switch ($key) {
                case 'lang':             return isset($t->lang)             ? $t->lang             : null;
                case 'name1':            return isset($t->name1)            ? $t->name1            : null;
                case 'name2':            return isset($t->name2)            ? $t->name2            : null;
                case 'name3':            return isset($t->name3)            ? $t->name3            : null;
                case 'description':      return isset($t->description)      ? $t->description      : null;
                case 'shortDescription': return isset($t->shortDescription) ? $t->shortDescription : null;
                case 'technicalData':    return isset($t->technicalData)    ? $t->technicalData    : null;
                case 'keywords':         return isset($t->keywords)         ? $t->keywords         : null;
                case 'metaDescription':  return isset($t->metaDescription)  ? $t->metaDescription  : null;
                case 'urlPath':          return isset($t->urlPath)          ? $t->urlPath          : null;
            }
        }
        return null;
    }

    private static function extractImages($item): array
    {
        $images = self::pick($item, 'itemImages');
        if ($images === null || (!is_array($images) && !is_object($images))) {
            return [];
        }

        $out = [];
        foreach ($images as $img) {
            $out[] = [
                'id'          => self::asInt(self::imageField($img, 'id')),
                'position'    => self::asInt(self::imageField($img, 'position')),
                'type'        => self::asString(self::imageField($img, 'type')),
                'file_type'   => self::asString(self::imageField($img, 'fileType')),
                'path'        => self::asString(self::imageField($img, 'path')),
                'url'         => self::asString(self::imageField($img, 'url')),
                'url_preview' => self::asString(self::imageField($img, 'urlPreview')),
                'url_middle'  => self::asString(self::imageField($img, 'urlMiddle')),
            ];
        }
        return $out;
    }

    private static function imageField($img, string $key)
    {
        if (is_array($img)) {
            return $img[$key] ?? null;
        }
        if (is_object($img)) {
            switch ($key) {
                case 'id':         return isset($img->id)         ? $img->id         : null;
                case 'position':   return isset($img->position)   ? $img->position   : null;
                case 'type':       return isset($img->type)       ? $img->type       : null;
                case 'fileType':   return isset($img->fileType)   ? $img->fileType   : null;
                case 'path':       return isset($img->path)       ? $img->path       : null;
                case 'url':        return isset($img->url)        ? $img->url        : null;
                case 'urlPreview': return isset($img->urlPreview) ? $img->urlPreview : null;
                case 'urlMiddle':  return isset($img->urlMiddle)  ? $img->urlMiddle  : null;
            }
        }
        return null;
    }

    private static function primaryName($item, string $preferredLang): ?string
    {
        $texts = self::extractTextsByLang($item);
        if (isset($texts[$preferredLang]['name1']) && is_string($texts[$preferredLang]['name1']) && $texts[$preferredLang]['name1'] !== '') {
            return $texts[$preferredLang]['name1'];
        }
        foreach ($texts as $byLang) {
            if (isset($byLang['name1']) && is_string($byLang['name1']) && $byLang['name1'] !== '') {
                return $byLang['name1'];
            }
        }
        return null;
    }

    private static function asInt($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v))     return $v;
        if (is_numeric($v)) return (int) $v;
        return null;
    }

    private static function asString($v): ?string
    {
        if ($v === null) return null;
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v) || is_bool($v)) return (string) $v;
        return null;
    }

    private static function asIsoDate($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (is_int($v))    return date('c', $v);
        if (is_string($v)) {
            $ts = strtotime($v);
            return $ts === false ? $v : date('c', $ts);
        }
        return null;
    }

    private static function baseMeta(Request $request): array
    {
        return [
            'fetched_at' => date('c'),
            'endpoint'   => '/rest/article-list-4711/external/articles',
        ];
    }

    // ------------------------------------------------------------------
    // Variations + Sub-Relations
    //
    // Hinweis zur Plenty-`with`-Resolver-Semantik: `ItemRepositoryContract::search()`
    // löst die Punkt-Notation für Variation-Sub-Relations nicht garantiert eager
    // auf. Wenn ein Sub-Feld leer (`[]` / `null`) ankommt, ist das kein Fehler im
    // Serializer — dann liefert Plenty die Daten schlicht nicht mit. Konsequenz für
    // Konsumenten: ein leeres `prices`/`stock`/etc. heißt "nicht geladen", nicht
    // "es gibt keine".
    // ------------------------------------------------------------------

    /**
     * Serialisiert die in loadVariationsByItemId() vorab geladene Varianten-Liste.
     * Anders als die früheren extract*-Methoden picken wir hier nicht aus dem Item,
     * sondern bekommen die schon korrekt geladenen Variation-Objekte direkt rein.
     */
    private static function serializeVariationList(array $variations): array
    {
        $out = [];
        foreach ($variations as $v) {
            $out[] = self::serializeVariation($v);
        }
        return $out;
    }

    private static function serializeVariation($v): array
    {
        return [
            'id'                  => self::asInt(self::prop($v, 'id')),
            'item_id'             => self::asInt(self::prop($v, 'itemId')),
            'number'              => self::asString(self::prop($v, 'number')),
            'is_main'             => self::asBool(self::prop($v, 'isMain')),
            'is_active'           => self::asBool(self::prop($v, 'isActive')),
            'position'            => self::asInt(self::prop($v, 'position')),
            'external_id'         => self::asString(self::prop($v, 'externalId')),
            'model'               => self::asString(self::prop($v, 'model')),
            'vat_id'              => self::asInt(self::prop($v, 'vatId')),
            'weight_g'            => self::asInt(self::prop($v, 'weightG')),
            'weight_net_g'        => self::asInt(self::prop($v, 'weightNetG')),
            'width_mm'            => self::asInt(self::prop($v, 'widthMM')),
            'length_mm'           => self::asInt(self::prop($v, 'lengthMM')),
            'height_mm'           => self::asInt(self::prop($v, 'heightMM')),
            'packing_units'       => self::asInt(self::prop($v, 'packingUnits')),
            'packing_unit_type_id'=> self::asInt(self::prop($v, 'packingUnitTypeId')),
            'main_warehouse_id'   => self::asInt(self::prop($v, 'mainWarehouseId')),
            'picking'             => self::asString(self::prop($v, 'picking')),
            'stock_limitation'    => self::asInt(self::prop($v, 'stockLimitation')),
            'released_at'         => self::asIsoDate(self::prop($v, 'releasedAt')),
            'available_until'     => self::asIsoDate(self::prop($v, 'availableUntil')),
            'created_at'          => self::asIsoDate(self::prop($v, 'createdAt')),
            'updated_at'          => self::asIsoDate(self::prop($v, 'updatedAt')),
            'prices'              => self::extractPrices(self::prop($v, 'variationSalesPrices')),
            'stock'               => self::extractStock(self::prop($v, 'stock')),
            'barcodes'            => self::extractBarcodes(self::prop($v, 'variationBarcodes')),
            'categories'          => self::extractCategories(self::prop($v, 'variationCategories')),
            'properties'          => self::extractProperties(self::prop($v, 'variationProperties')),
            'clients'             => self::extractClients(self::prop($v, 'variationClients')),
            'markets'             => self::extractMarkets(self::prop($v, 'variationMarkets')),
            'attribute_values'    => self::extractAttributeValues(self::prop($v, 'variationAttributeValues')),
            'unit'                => self::serializeUnit(self::prop($v, 'unit')),
        ];
    }

    /** Gemeinsamer "iterable or empty"-Check für alle Sub-Collections. */
    private static function isIterable_($c): bool
    {
        return is_array($c) || is_object($c);
    }

    private static function extractPrices($c): array
    {
        if (!self::isIterable_($c)) return [];
        $out = [];
        foreach ($c as $p) { $out[] = self::serializePrice($p); }
        return $out;
    }

    private static function extractStock($c): array
    {
        if (!self::isIterable_($c)) return [];
        $out = [];
        foreach ($c as $s) { $out[] = self::serializeStock($s); }
        return $out;
    }

    private static function extractBarcodes($c): array
    {
        if (!self::isIterable_($c)) return [];
        $out = [];
        foreach ($c as $b) { $out[] = self::serializeBarcode($b); }
        return $out;
    }

    private static function extractCategories($c): array
    {
        if (!self::isIterable_($c)) return [];
        $out = [];
        foreach ($c as $cat) { $out[] = self::serializeCategory($cat); }
        return $out;
    }

    private static function extractProperties($c): array
    {
        if (!self::isIterable_($c)) return [];
        $out = [];
        foreach ($c as $p) { $out[] = self::serializeProperty($p); }
        return $out;
    }

    private static function extractClients($c): array
    {
        if (!self::isIterable_($c)) return [];
        $out = [];
        foreach ($c as $cl) { $out[] = self::serializeClient($cl); }
        return $out;
    }

    private static function extractMarkets($c): array
    {
        if (!self::isIterable_($c)) return [];
        $out = [];
        foreach ($c as $m) { $out[] = self::serializeMarket($m); }
        return $out;
    }

    private static function extractAttributeValues($c): array
    {
        if (!self::isIterable_($c)) return [];
        $out = [];
        foreach ($c as $a) { $out[] = self::serializeAttributeValue($a); }
        return $out;
    }

    private static function serializePrice($p): array
    {
        $salesPriceId = self::asInt(self::prop($p, 'salesPriceId'));
        $currency = ($salesPriceId !== null && isset(self::$currencyMap[$salesPriceId]))
            ? self::$currencyMap[$salesPriceId]
            : null;
        return [
            'sales_price_id' => $salesPriceId,
            'price'          => self::asFloat(self::prop($p, 'price')),
            'currency'       => $currency,
            'updated_at'     => self::asIsoDate(self::prop($p, 'updatedAt')),
        ];
    }

    private static function serializeStock($s): array
    {
        return [
            'warehouse_id'    => self::asInt(self::prop($s, 'warehouseId')),
            'stock_net'       => self::asFloat(self::prop($s, 'stockNet')),
            'physical_stock'  => self::asFloat(self::prop($s, 'physicalStock')),
            'reserved_stock'  => self::asFloat(self::prop($s, 'reservedStock')),
            'updated_at'      => self::asIsoDate(self::prop($s, 'updatedAt')),
        ];
    }

    private static function serializeBarcode($b): array
    {
        return [
            'barcode_id' => self::asInt(self::prop($b, 'barcodeId')),
            'code'       => self::asString(self::prop($b, 'code')),
            'created_at' => self::asIsoDate(self::prop($b, 'createdAt')),
        ];
    }

    private static function serializeCategory($c): array
    {
        return [
            'category_id' => self::asInt(self::prop($c, 'categoryId')),
            'plenty_id'   => self::asInt(self::prop($c, 'plentyId')),
            'position'    => self::asInt(self::prop($c, 'position')),
            'is_default'  => self::asBool(self::prop($c, 'isDefault')),
        ];
    }

    private static function serializeProperty($p): array
    {
        return [
            'property_id'     => self::asInt(self::prop($p, 'propertyId')),
            'value_int'       => self::asInt(self::prop($p, 'valueInt')),
            'value_float'     => self::asFloat(self::prop($p, 'valueFloat')),
            'value_string'    => self::asString(self::prop($p, 'valueString')),
            'value_selection' => self::asInt(self::prop($p, 'valueSelection')),
            'surcharge'       => self::asFloat(self::prop($p, 'surcharge')),
        ];
    }

    private static function serializeClient($c): array
    {
        return [
            'plenty_id' => self::asInt(self::prop($c, 'plentyId')),
        ];
    }

    private static function serializeMarket($m): array
    {
        return [
            'market_id'   => self::asInt(self::prop($m, 'marketId')),
            'sku'         => self::asString(self::prop($m, 'sku')),
            'initial_sku' => self::asString(self::prop($m, 'initialSku')),
        ];
    }

    private static function serializeAttributeValue($a): array
    {
        return [
            'attribute_id'       => self::asInt(self::prop($a, 'attributeId')),
            'attribute_value_id' => self::asInt(self::prop($a, 'attributeValueId')),
        ];
    }

    private static function serializeUnit($u)
    {
        if ($u === null || (!is_array($u) && !is_object($u))) {
            return null;
        }
        return [
            'unit_id' => self::asInt(self::prop($u, 'unitId')),
            'content' => self::asFloat(self::prop($u, 'content')),
        ];
    }

    /**
     * Sandbox-konformer Property-Zugriff für alle Variation-Sub-Typen.
     * Ein zentraler switch statt 10 spiegelbildlicher Field-Helper —
     * die Property-Namen kollidieren nicht praktisch (mehrere Sub-Typen
     * haben `id`/`updatedAt`/etc., aber `$obj->id` funktioniert für alle).
     */
    private static function prop($obj, string $key)
    {
        if (is_array($obj)) {
            return $obj[$key] ?? null;
        }
        if (!is_object($obj)) {
            return null;
        }
        switch ($key) {
            // Variation core
            case 'id':                          return isset($obj->id)                          ? $obj->id                          : null;
            case 'itemId':                      return isset($obj->itemId)                      ? $obj->itemId                      : null;
            case 'isMain':                      return isset($obj->isMain)                      ? $obj->isMain                      : null;
            case 'isActive':                    return isset($obj->isActive)                    ? $obj->isActive                    : null;
            case 'number':                      return isset($obj->number)                      ? $obj->number                      : null;
            case 'position':                    return isset($obj->position)                    ? $obj->position                    : null;
            case 'externalId':                  return isset($obj->externalId)                  ? $obj->externalId                  : null;
            case 'model':                       return isset($obj->model)                       ? $obj->model                       : null;
            case 'vatId':                       return isset($obj->vatId)                       ? $obj->vatId                       : null;
            case 'weightG':                     return isset($obj->weightG)                     ? $obj->weightG                     : null;
            case 'weightNetG':                  return isset($obj->weightNetG)                  ? $obj->weightNetG                  : null;
            case 'widthMM':                     return isset($obj->widthMM)                     ? $obj->widthMM                     : null;
            case 'lengthMM':                    return isset($obj->lengthMM)                    ? $obj->lengthMM                    : null;
            case 'heightMM':                    return isset($obj->heightMM)                    ? $obj->heightMM                    : null;
            case 'packingUnits':                return isset($obj->packingUnits)                ? $obj->packingUnits                : null;
            case 'packingUnitTypeId':           return isset($obj->packingUnitTypeId)           ? $obj->packingUnitTypeId           : null;
            case 'mainWarehouseId':             return isset($obj->mainWarehouseId)             ? $obj->mainWarehouseId             : null;
            case 'picking':                     return isset($obj->picking)                     ? $obj->picking                     : null;
            case 'stockLimitation':             return isset($obj->stockLimitation)             ? $obj->stockLimitation             : null;
            case 'releasedAt':                  return isset($obj->releasedAt)                  ? $obj->releasedAt                  : null;
            case 'availableUntil':              return isset($obj->availableUntil)              ? $obj->availableUntil              : null;
            case 'createdAt':                   return isset($obj->createdAt)                   ? $obj->createdAt                   : null;
            case 'updatedAt':                   return isset($obj->updatedAt)                   ? $obj->updatedAt                   : null;
            // Variation sub-collections
            case 'variationSalesPrices':        return isset($obj->variationSalesPrices)        ? $obj->variationSalesPrices        : null;
            case 'stock':                       return isset($obj->stock)                       ? $obj->stock                       : null;
            case 'variationBarcodes':           return isset($obj->variationBarcodes)           ? $obj->variationBarcodes           : null;
            case 'variationCategories':         return isset($obj->variationCategories)         ? $obj->variationCategories         : null;
            case 'variationProperties':         return isset($obj->variationProperties)         ? $obj->variationProperties         : null;
            case 'variationClients':            return isset($obj->variationClients)            ? $obj->variationClients            : null;
            case 'variationMarkets':            return isset($obj->variationMarkets)            ? $obj->variationMarkets            : null;
            case 'variationAttributeValues':    return isset($obj->variationAttributeValues)    ? $obj->variationAttributeValues    : null;
            case 'unit':                        return isset($obj->unit)                        ? $obj->unit                        : null;
            // SalesPrice
            case 'salesPriceId':                return isset($obj->salesPriceId)                ? $obj->salesPriceId                : null;
            case 'price':                       return isset($obj->price)                       ? $obj->price                       : null;
            case 'currency':                    return isset($obj->currency)                    ? $obj->currency                    : null;
            // Stock
            case 'warehouseId':                 return isset($obj->warehouseId)                 ? $obj->warehouseId                 : null;
            case 'stockNet':                    return isset($obj->stockNet)                    ? $obj->stockNet                    : null;
            case 'physicalStock':               return isset($obj->physicalStock)               ? $obj->physicalStock               : null;
            case 'reservedStock':               return isset($obj->reservedStock)               ? $obj->reservedStock               : null;
            // Barcode
            case 'barcodeId':                   return isset($obj->barcodeId)                   ? $obj->barcodeId                   : null;
            case 'code':                        return isset($obj->code)                        ? $obj->code                        : null;
            // Category
            case 'categoryId':                  return isset($obj->categoryId)                  ? $obj->categoryId                  : null;
            case 'plentyId':                    return isset($obj->plentyId)                    ? $obj->plentyId                    : null;
            case 'isDefault':                   return isset($obj->isDefault)                   ? $obj->isDefault                   : null;
            // Property
            case 'propertyId':                  return isset($obj->propertyId)                  ? $obj->propertyId                  : null;
            case 'valueInt':                    return isset($obj->valueInt)                    ? $obj->valueInt                    : null;
            case 'valueFloat':                  return isset($obj->valueFloat)                  ? $obj->valueFloat                  : null;
            case 'valueString':                 return isset($obj->valueString)                 ? $obj->valueString                 : null;
            case 'valueSelection':              return isset($obj->valueSelection)              ? $obj->valueSelection              : null;
            case 'surcharge':                   return isset($obj->surcharge)                   ? $obj->surcharge                   : null;
            // Market
            case 'marketId':                    return isset($obj->marketId)                    ? $obj->marketId                    : null;
            case 'sku':                         return isset($obj->sku)                         ? $obj->sku                         : null;
            case 'initialSku':                  return isset($obj->initialSku)                  ? $obj->initialSku                  : null;
            // Unit
            case 'unitId':                      return isset($obj->unitId)                      ? $obj->unitId                      : null;
            case 'content':                     return isset($obj->content)                     ? $obj->content                     : null;
            // AttributeValue
            case 'attributeId':                 return isset($obj->attributeId)                 ? $obj->attributeId                 : null;
            case 'attributeValueId':            return isset($obj->attributeValueId)            ? $obj->attributeValueId            : null;
        }
        return null;
    }

    private static function asBool($v): ?bool
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v !== 0;
        if (is_string($v)) {
            if ($v === '1' || strcasecmp($v, 'true') === 0)  return true;
            if ($v === '0' || strcasecmp($v, 'false') === 0) return false;
        }
        return null;
    }

    private static function asFloat($v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_float($v))   return $v;
        if (is_int($v))     return (float) $v;
        if (is_numeric($v)) return (float) $v;
        return null;
    }
}
