# ArticleList4711

Plentymarkets-Plugin, das im Backend eine einfache Liste der ersten 10 Artikel ausgibt (Item-ID + Name).

## Aufruf nach Installation

Nach Aktivierung des Plugins im gewünschten Plugin-Set ist die Liste erreichbar unter:

```
https://<dein-plenty-shop>/plugin/article-list-4711/articles
```

Der Aufruf läuft im Backend-Kontext — ein eingeloggter Backend-User wird vorausgesetzt. Die Itemabfrage selbst läuft über `AuthHelper::processUnguarded`, damit die ACL-Prüfung mit dem Admin-Login auskommt.

## Aufbau

```
plugin.json                                          Plugin-Manifest
src/Providers/ArticleListServiceProvider.php         Registriert die Route
src/Controllers/ArticleListController.php            Holt 10 Items, übergibt sie an die View
resources/views/ArticleList.twig                     HTML-Tabelle
```

## Datenquelle

`Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract::search([], ['*'], [], 1, 10)` — liefert die erste Seite mit 10 Items inkl. `texts[]`. Der Name wird aus dem ersten verfügbaren `texts[].name1` gezogen.

Falls du stattdessen Varianten oder ElasticSearch-basierte Suche willst, lässt sich der Controller auf `VariationSearchRepositoryContract` bzw. `VariationElasticSearchSearchRepositoryContract` umstellen.

## Deployment

1. Plugin-Set in Plenty öffnen → Plugin via Git-URL hinzufügen.
2. Branch `main` wählen, installieren, ins Plugin-Set einbinden, deployen.
3. URL oben aufrufen.
