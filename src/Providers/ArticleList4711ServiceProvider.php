<?php

namespace ArticleList4711\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Routing\Router;
use Plenty\Plugin\Routing\ApiRouter;

class ArticleList4711ServiceProvider extends ServiceProvider
{
    public function register()
    {
    }

    public function boot(Router $router, ApiRouter $apiRouter)
    {
        // Frontend-Route (Bookmark / Fallback) — rendert eine Twig-Tabelle.
        $router->get(
            'plugin/article-list-4711/articles',
            'ArticleList4711\Controllers\ArticleListController@showList'
        );

        // REST-Endpoint für die Backend-UI (ui/index.html).
        $apiRouter->get(
            'article-list-4711/articles',
            'ArticleList4711\Controllers\ArticleListApiController@index'
        );

        // Externer Endpoint — paginierter Artikel-Export, X-Api-Key-Auth.
        $apiRouter->get(
            'article-list-4711/external/articles',
            'ArticleList4711\Controllers\ExternalArticleController@index'
        );
    }
}
