<?php

namespace ArticleList4711\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

class ExternalArticleController extends Controller
{
    /** Schema-Version des Response-Envelopes. Erhöhen, wenn sich das Format inkompatibel ändert. */
    const SCHEMA_VERSION = '1';

    const DEFAULT_PER_PAGE = 50;
    const MAX_PER_PAGE     = 200;

    public function index(
        Request $request,
        Response $response,
        ConfigRepository $config,
        ItemRepositoryContract $itemRepository,
        AuthHelper $authHelper
    ) {
        // --- Auth -----------------------------------------------------------
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

        // --- Paginierung ----------------------------------------------------
        $page    = (int) $request->get('page', 1);
        $perPage = (int) $request->get('per_page', self::DEFAULT_PER_PAGE);
        if ($page    < 1) $page    = 1;
        if ($perPage < 1) $perPage = self::DEFAULT_PER_PAGE;
        if ($perPage > self::MAX_PER_PAGE) $perPage = self::MAX_PER_PAGE;

        $lang = (string) $request->get('lang', 'de');
        if ($lang === '') $lang = 'de';

        // --- Daten holen ----------------------------------------------------
        $paginated = $authHelper->processUnguarded(function () use ($itemRepository, $page, $perPage, $lang) {
            return $itemRepository->search([], [$lang], $page, $perPage, ['texts']);
        });

        $entries = $paginated->getResult();

        $articles = [];
        if (is_array($entries) || is_object($entries)) {
            foreach ($entries as $item) {
                $articles[] = self::serializeArticle($item, $lang);
            }
        }

        // --- Pagination-Metadaten via toArray() (laut Plenty-Doku verfügbar) -
        $totalCount  = null;
        $isLastPage  = null;
        $lastPageNum = null;
        $paginatedArr = $paginated->toArray();
        if (is_array($paginatedArr)) {
            if (isset($paginatedArr['totalsCount']))    $totalCount  = (int) $paginatedArr['totalsCount'];
            if (isset($paginatedArr['isLastPage']))     $isLastPage  = (bool) $paginatedArr['isLastPage'];
            if (isset($paginatedArr['lastPageNumber'])) $lastPageNum = (int) $paginatedArr['lastPageNumber'];
        }

        return $response->json([
            'data' => $articles,
            'pagination' => [
                'page'           => $page,
                'per_page'       => $perPage,
                'returned_count' => count($articles),
                'total_count'    => $totalCount,
                'last_page'      => $lastPageNum,
                'is_last_page'   => $isLastPage,
                'has_next_page'  => $isLastPage === null ? null : ! $isLastPage,
            ],
            'meta' => self::baseMeta($request) + [
                'lang'           => $lang,
                'with'           => ['texts'],
                'schema_version' => self::SCHEMA_VERSION,
            ],
        ], 200);
    }

    /**
     * Wandelt ein Plenty-Item (Array oder Objekt) in das Export-Schema.
     * Sandbox-konform: nur is_*, isset, get_class, kein method_exists,
     * keine dynamischen Property-Namen.
     */
    private static function serializeArticle($item, string $lang): array
    {
        return [
            'id'               => self::asInt(self::pick($item, 'id')),
            'position'         => self::asInt(self::pick($item, 'position')),
            'manufacturer_id'  => self::asInt(self::pick($item, 'manufacturerId')),
            'stock_limitation' => self::asInt(self::pick($item, 'stockLimitation')),
            'created_at'       => self::asIsoDate(self::pick($item, 'createdAt')),
            'updated_at'       => self::asIsoDate(self::pick($item, 'updatedAt')),
            'texts_by_lang'    => self::extractTextsByLang($item),
            'primary_name'     => self::primaryName($item, $lang),
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
                case 'createdAt':       return isset($item->createdAt)       ? $item->createdAt       : null;
                case 'updatedAt':       return isset($item->updatedAt)       ? $item->updatedAt       : null;
                case 'texts':           return isset($item->texts)           ? $item->texts           : null;
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
}
