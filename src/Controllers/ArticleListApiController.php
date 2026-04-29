<?php

namespace ArticleList4711\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

class ArticleListApiController extends Controller
{
    public function index(
        Request $request,
        ItemRepositoryContract $itemRepository,
        AuthHelper $authHelper
    ): array {
        if ($request->get('debug')) {
            return ['debug' => ArticleListLoader::debug($itemRepository, $authHelper)];
        }
        return ['articles' => ArticleListLoader::load($itemRepository, $authHelper)];
    }
}
