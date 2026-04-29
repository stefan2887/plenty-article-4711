<?php

namespace ArticleList4711\Controllers;

use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

class ArticleListLoader
{
    /**
     * Liefert max. 10 Artikel als id/name-Paare.
     *
     * Korrekte Signatur laut plentymarkets/plugin-interface:
     *   search($columns = [], $lang = [], int $page = 1, int $itemsPerPage = 50, array $with = [])
     */
    public static function load(
        ItemRepositoryContract $itemRepository,
        AuthHelper $authHelper
    ): array {
        $result = $authHelper->processUnguarded(function () use ($itemRepository) {
            return $itemRepository->search([], ['de'], 1, 10, ['texts']);
        });

        $entries = $result->getResult();

        $articles = [];
        foreach ($entries as $item) {
            $id = self::idOf($item);
            if ($id === null) {
                continue;
            }
            $articles[] = [
                'id'   => $id,
                'name' => self::nameOf($item) ?? '(kein Name)',
            ];
        }
        return $articles;
    }

    /**
     * Debug-Variante: gibt das rohe Resultat in einer
     * sandbox-konformen Form zurück, damit Plenty-Datenstruktur
     * sichtbar wird, wenn die Liste leer bleibt.
     */
    public static function debug(
        ItemRepositoryContract $itemRepository,
        AuthHelper $authHelper
    ): array {
        $result = $authHelper->processUnguarded(function () use ($itemRepository) {
            return $itemRepository->search([], ['de'], 1, 10, ['texts']);
        });

        $entries = $result->getResult();

        $info = [
            'result_class'    => self::typeOf($result),
            'entries_type'    => self::typeOf($entries),
            'entries_count'   => is_array($entries) ? count($entries) : null,
            'first_item_type' => null,
            'first_item_keys' => null,
            'first_item_json' => null,
        ];

        if (is_array($entries) && !empty($entries)) {
            $first = reset($entries);
            $info['first_item_type'] = self::typeOf($first);
            if (is_array($first)) {
                $info['first_item_keys'] = array_keys($first);
            }
            $info['first_item_json'] = json_encode($first);
        }

        return $info;
    }

    private static function typeOf($v): string
    {
        if (is_array($v))   return 'array';
        if (is_object($v))  return 'object:' . get_class($v);
        if (is_string($v))  return 'string';
        if (is_int($v))     return 'int';
        if (is_float($v))   return 'float';
        if (is_bool($v))    return 'bool';
        if (is_null($v))    return 'null';
        return 'unknown';
    }

    private static function idOf($item)
    {
        if (is_array($item)) {
            return $item['id'] ?? null;
        }
        if (is_object($item) && isset($item->id)) {
            return $item->id;
        }
        return null;
    }

    private static function nameOf($item)
    {
        $texts = null;
        if (is_array($item)) {
            $texts = $item['texts'] ?? null;
        } elseif (is_object($item) && isset($item->texts)) {
            $texts = $item->texts;
        }

        if ($texts === null) {
            return null;
        }
        if (!is_array($texts) && !is_object($texts)) {
            return null;
        }

        foreach ($texts as $text) {
            if (is_array($text) && !empty($text['name1'])) {
                return $text['name1'];
            }
            if (is_object($text) && isset($text->name1) && $text->name1 !== '') {
                return $text->name1;
            }
        }
        return null;
    }
}
