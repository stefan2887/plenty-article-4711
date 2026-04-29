<?php

namespace ArticleList4711\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Templates\Twig;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

class ArticleListController extends Controller
{
    public function showList(
        Twig $twig,
        ItemRepositoryContract $itemRepository,
        AuthHelper $authHelper
    ): string {
        // Itemdaten dürfen nur mit gültiger Auth gelesen werden — daher als
        // privilegierter Aufruf, damit der Backend-Login des Admins reicht.
        $items = $authHelper->processUnguarded(function () use ($itemRepository) {
            return $itemRepository->search([], ['*'], [], 1, 10);
        });

        $articles = [];
        foreach ($items->getResult() as $item) {
            $articles[] = [
                'id'   => $item['id'] ?? '',
                'name' => $this->extractName($item),
            ];
        }

        return $twig->render('ArticleList4711::ArticleList', [
            'articles' => $articles,
        ]);
    }

    private function extractName(array $item): string
    {
        if (!empty($item['texts']) && is_array($item['texts'])) {
            foreach ($item['texts'] as $text) {
                if (!empty($text['name1'])) {
                    return $text['name1'];
                }
            }
        }
        return '(kein Name)';
    }
}
