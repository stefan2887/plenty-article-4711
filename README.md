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

## Externer Endpoint (Datendrehscheibe)

Paginierter Artikel-Export mit eigener API-Key-Auth — gedacht für externe Systeme (ETL, Sync, Datendrehscheibe).

```
GET /rest/article-list-4711/external/articles?page=1&per_page=100&lang=de
Header: X-Api-Key: <der-im-Backend-konfigurierte-Key>
```

**API-Key setzen:** Plenty-Backend → **Plugins → Plugin-Übersicht → ArticleList4711 → Konfiguration**, Tab *Externer Zugriff*. Solange der Key leer ist, wird **jeder Aufruf mit 401 abgelehnt** (kein Default-Key).

**Query-Parameter:**

| Param | Default | Max | Beschreibung |
|---|---|---|---|
| `page` | `1` | — | 1-basierte Seite |
| `per_page` | `50` | `200` | Items pro Seite (hartes Maximum: 200) |
| `lang` | `de` | — | Bevorzugte Sprache für `primary_name`; `texts_by_lang` enthält alle verfügbaren Sprachen |

**Response-Schema (`schema_version: "1"`):**

```json
{
  "data": [
    {
      "id": 12345,
      "position": 0,
      "manufacturer_id": null,
      "stock_limitation": 0,
      "created_at": "2024-01-15T08:30:00+00:00",
      "updated_at": "2026-04-29T10:15:00+00:00",
      "primary_name": "Artikelname Deutsch",
      "texts_by_lang": {
        "de": {
          "name1": "Artikelname Deutsch",
          "name2": null,
          "name3": null,
          "description": "...",
          "short_description": null,
          "technical_data": null,
          "meta_keywords": null,
          "meta_description": null,
          "url_path": "artikelname-deutsch"
        }
      }
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 100,
    "returned_count": 100,
    "total_count": 12345,
    "last_page": 124,
    "is_last_page": false,
    "has_next_page": true
  },
  "meta": {
    "fetched_at": "2026-04-29T14:32:00+00:00",
    "endpoint": "/rest/article-list-4711/external/articles",
    "lang": "de",
    "with": ["texts"],
    "schema_version": "1"
  }
}
```

**Loop-Konvention für vollständigen Sync (Pseudocode):**

```
page = 1
loop:
  resp = GET .../external/articles?page=$page&per_page=200
  process(resp.data)
  if resp.pagination.has_next_page is false: break
  page = page + 1
```

**Auth-Fehler-Schema:**

```json
{
  "error": { "code": "unauthorized", "message": "Missing or invalid X-Api-Key header." },
  "meta":  { "fetched_at": "...", "endpoint": "..." }
}
```

Stabilitätsgarantien:
- snake_case Feldnamen.
- ISO-8601-Zeitstempel inkl. Offset.
- Felder, die unbekannt/leer sind, sind explizit `null` (statt zu fehlen).
- Schema-Inkrement (`schema_version`) bei brechenden Änderungen.

## Aufbau

```
plugin.json                                            Plugin-Manifest (type: backend)
config.json                                            Plugin-Konfiguration (API-Key)
ui.json                                                Backend-Menüeintrag
ui/index.html                                          Backend-View (Iframe-Inhalt)
src/Providers/ArticleList4711ServiceProvider.php       Router + ApiRouter
src/Controllers/ArticleListController.php              Twig-Route (HTML)
src/Controllers/ArticleListApiController.php           Backend-REST (JSON, Session-Auth)
src/Controllers/ExternalArticleController.php          Externer REST (JSON, X-Api-Key)
src/Controllers/ArticleListLoader.php                  Geteilter Item-Loader
resources/views/ArticleList.twig                       HTML-Tabelle für die Twig-Route
```

`plugin.json` muss `"type": "backend"` setzen — nur damit serviert Plenty die `ui/`-Assets unter dem internen Iframe-Pfad. Mit `"type": "plugin"` wird die `ui.json` zwar gelesen (Menüeintrag erscheint), die View-Datei aber nicht ausgeliefert (404 im iframe).

## Datenquelle

`Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract::search([], ['*'], [], 1, 10)` in `AuthHelper::processUnguarded`. Der Name wird aus dem ersten verfügbaren `texts[].name1` gezogen.

Bei Bedarf umstellbar auf `VariationSearchRepositoryContract` oder `VariationElasticSearchSearchRepositoryContract`.

## Hinweise zur Plenty-Sandbox

Plenty validiert PHP-Plugins gegen eine Funktions-Allowlist — `file_get_contents`, `dirname`, etc. sind nicht erlaubt. Pfad-/Dateioperationen sollten ausschließlich über die offiziellen SDK-Services laufen.

## Deployment

1. Plugin-Set in Plenty öffnen → Plugin via Git-URL hinzufügen.
2. Branch `main` wählen, installieren, ins Plugin-Set einbinden, deployen.
3. Backend neu laden — der Menüeintrag erscheint unter **Artikel → Artikelliste 4711**.
