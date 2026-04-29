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
        $articles = ArticleListLoader::load($itemRepository, $authHelper);

        return $twig->render('ArticleList4711::ArticleList', [
            'articles' => $articles,
        ]);
    }
}
