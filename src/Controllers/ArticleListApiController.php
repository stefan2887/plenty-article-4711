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
        return ['articles' => ArticleListLoader::load($itemRepository, $authHelper)];
    }
}
