<?php

namespace ArticleList4711\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

class ArticleListApiController extends Controller
{
    public function index(
        ItemRepositoryContract $itemRepository,
        AuthHelper $authHelper
    ): array {
        $items = $authHelper->processUnguarded(function () use ($itemRepository) {
            return $itemRepository->search([], ['*'], [], 1, 10);
        });

        $articles = [];
        foreach ($items->getResult() as $item) {
            $articles[] = [
                'id'   => $item['id'] ?? null,
                'name' => $this->extractName($item),
            ];
        }

        return ['articles' => $articles];
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
