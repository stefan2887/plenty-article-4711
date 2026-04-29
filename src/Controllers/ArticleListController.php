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

    public function showSource(Twig $twig): string
    {
        $root = dirname(__DIR__, 2);

        $files = [
            'plugin.json'                                  => $root . '/plugin.json',
            'src/Providers/ArticleListServiceProvider.php' => $root . '/src/Providers/ArticleListServiceProvider.php',
            'src/Controllers/ArticleListController.php'    => __FILE__,
            'resources/views/ArticleList.twig'             => $root . '/resources/views/ArticleList.twig',
            'resources/views/Source.twig'                  => $root . '/resources/views/Source.twig',
            'README.md'                                    => $root . '/README.md',
        ];

        $rendered = [];
        foreach ($files as $label => $path) {
            $content = @file_get_contents($path);
            $rendered[] = [
                'name'    => $label,
                'lang'    => $this->guessLang($label),
                'code'    => $content === false ? '(Datei nicht lesbar: ' . $path . ')' : $content,
                'missing' => $content === false,
            ];
        }

        return $twig->render('ArticleList4711::Source', [
            'files' => $rendered,
            'root'  => $root,
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

    private function guessLang(string $name): string
    {
        if (substr($name, -4) === '.php')  return 'php';
        if (substr($name, -5) === '.twig') return 'twig';
        if (substr($name, -5) === '.json') return 'json';
        if (substr($name, -3) === '.md')   return 'markdown';
        return 'text';
    }
}
