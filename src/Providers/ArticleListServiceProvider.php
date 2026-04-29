<?php

namespace ArticleList4711\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Routing\Router;

class ArticleListServiceProvider extends ServiceProvider
{
    public function register()
    {
    }

    public function boot(Router $router)
    {
        $router->get(
            'plugin/article-list-4711/articles',
            'ArticleList4711\Controllers\ArticleListController@showList'
        );

        $router->get(
            'plugin/article-list-4711/source',
            'ArticleList4711\Controllers\ArticleListController@showSource'
        );
    }
}
