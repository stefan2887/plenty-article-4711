# ArticleList4711

Plentymarkets-Plugin, das im Backend eine einfache Liste der ersten 10 Artikel ausgibt (Item-ID + Name).

## Aufruf nach Installation

Nach Aktivierung des Plugins im gewünschten Plugin-Set sind zwei Routen erreichbar:

```
https://<dein-plenty-shop>/plugin/article-list-4711/articles   → Tabelle mit 10 Artikeln
https://<dein-plenty-shop>/plugin/article-list-4711/source     → Quellcode aller Plugindateien
```

Der Aufruf läuft im Backend-Kontext — ein eingeloggter Backend-User wird vorausgesetzt. Die Itemabfrage selbst läuft über `AuthHelper::processUnguarded`, damit die ACL-Prüfung mit dem Admin-Login auskommt.

## Open Source / Quellcode-Sichtbarkeit

Plentymarkets-Plugins sind grundsätzlich Open Source aus Sicht des Shopbetreibers — alle PHP-/Twig-Dateien werden vom Plenty-System direkt aus diesem Git-Repository ausgeliefert und können im Backend unter **Plugins → Plugin-Übersicht → ArticleList4711 → Dateibrowser** eingesehen werden. Es findet keine Kompilierung oder Verschlüsselung statt.

Zusätzlich rendert die Route `/plugin/article-list-4711/source` alle Plugindateien live in einer HTML-Ansicht, sodass der ausgeführte Code ohne Umweg über den Dateibrowser sichtbar ist.

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
