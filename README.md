# ArticleList4711

Plentymarkets-Plugin, das die ersten 10 Artikel auflistet — sowohl als echter Backend-Menüeintrag als auch unter einer eigenen URL.

## Backend-Integration

Nach Deploy taucht im Plenty-Admin unter **Artikel** ein neuer Menüpunkt **Artikelliste 4711** auf. Er öffnet einen Backend-View, der per REST die ersten 10 Artikel lädt und tabellarisch anzeigt.

Wiring:

- `ui.json` registriert den Menüeintrag (`menu: item`, `urlKey: article-list-4711`).
- `ui/index.html` ist der eingebettete Backend-View (vanilla HTML/JS, kein Build-Schritt).
- `src/Controllers/ArticleListApiController.php` bedient den REST-Endpoint, der von der HTML-Seite konsumiert wird:

```
GET /rest/article-list-4711/articles
→ { "articles": [ { "id": 123, "name": "..." }, ... ] }
```

## Direkte URL (Fallback)

Auch ohne Backend-Menü erreichbar:

```
https://<dein-plenty-shop>/plugin/article-list-4711/articles
```

Rendert die gleiche Liste serverseitig per Twig.

## Aufbau

```
plugin.json                                            Plugin-Manifest
ui.json                                                Backend-Menüeintrag
ui/index.html                                          Backend-View (Iframe-Inhalt)
src/Providers/ArticleList4711ServiceProvider.php       Router + ApiRouter
src/Controllers/ArticleListController.php              Twig-Route (HTML)
src/Controllers/ArticleListApiController.php           REST-Endpoint (JSON)
resources/views/ArticleList.twig                       HTML-Tabelle für die Twig-Route
```

## Datenquelle

`Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract::search([], ['*'], [], 1, 10)` in `AuthHelper::processUnguarded`. Der Name wird aus dem ersten verfügbaren `texts[].name1` gezogen.

Bei Bedarf umstellbar auf `VariationSearchRepositoryContract` oder `VariationElasticSearchSearchRepositoryContract`.

## Hinweise zur Plenty-Sandbox

Plenty validiert PHP-Plugins gegen eine Funktions-Allowlist — `file_get_contents`, `dirname`, etc. sind nicht erlaubt. Pfad-/Dateioperationen sollten ausschließlich über die offiziellen SDK-Services laufen.

## Deployment

1. Plugin-Set in Plenty öffnen → Plugin via Git-URL hinzufügen.
2. Branch `main` wählen, installieren, ins Plugin-Set einbinden, deployen.
3. Backend neu laden — der Menüeintrag erscheint unter **Artikel → Artikelliste 4711**.
