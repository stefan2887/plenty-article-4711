<?php

namespace ArticleList4711\Controllers;

use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

class ArticleListLoader
{
    /**
     * Liefert max. 10 Artikel als einfache id/name-Paare. Defensiv gegen
     * unterschiedliche Rückgabeformen von ItemRepositoryContract::search()
     * (PaginatedResult, Collection, plain array, Item-Objekte, …).
     *
     * Korrekte Signatur laut plentymarkets/plugin-interface:
     *   search($columns = [], $lang = [], int $page = 1, int $itemsPerPage = 50, array $with = [])
     */
    public static function load(
        ItemRepositoryContract $itemRepository,
        AuthHelper $authHelper
    ): array {
        $raw = $authHelper->processUnguarded(function () use ($itemRepository) {
            return $itemRepository->search(['*'], ['de'], 1, 10, []);
        });

        $entries = self::asIterable($raw);

        $articles = [];
        foreach ($entries as $item) {
            $id   = self::field($item, 'id');
            $name = self::extractName($item);
            if ($id === null && $name === null) {
                continue;
            }
            $articles[] = [
                'id'   => $id,
                'name' => $name ?? '(kein Name)',
            ];
        }
        return $articles;
    }

    private static function asIterable($value)
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            if (method_exists($value, 'getResult')) {
                $r = $value->getResult();
                return is_array($r) ? $r : (is_object($r) && method_exists($r, 'toArray') ? $r->toArray() : []);
            }
            if (method_exists($value, 'toArray')) {
                $r = $value->toArray();
                if (isset($r['entries']) && is_array($r['entries'])) {
                    return $r['entries'];
                }
                return is_array($r) ? $r : [];
            }
        }
        return [];
    }

    private static function field($item, string $key)
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }
        if (is_object($item)) {
            if (isset($item->{$key})) {
                return $item->{$key};
            }
            if (method_exists($item, 'toArray')) {
                $arr = $item->toArray();
                return is_array($arr) ? ($arr[$key] ?? null) : null;
            }
        }
        return null;
    }

    private static function extractName($item)
    {
        $texts = self::field($item, 'texts');
        if (!is_array($texts)) {
            return null;
        }
        foreach ($texts as $text) {
            $name = self::field($text, 'name1');
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        return null;
    }
}
