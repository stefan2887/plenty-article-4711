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
            return $itemRepository->search(['*'], ['de'], 1, 10);
        });

        $articles = [];
        foreach ($result->getResult() as $item) {
            if (!is_array($item)) {
                continue;
            }
            $articles[] = [
                'id'   => $item['id'] ?? null,
                'name' => self::extractName($item),
            ];
        }
        return $articles;
    }

    private static function extractName(array $item): string
    {
        if (!empty($item['texts']) && is_array($item['texts'])) {
            foreach ($item['texts'] as $text) {
                if (is_array($text) && !empty($text['name1'])) {
                    return $text['name1'];
                }
            }
        }
        return '(kein Name)';
    }
}
