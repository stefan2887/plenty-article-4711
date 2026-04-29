# ArticleList4711

Plentymarkets-Plugin, das eine einfache Liste der ersten 10 Artikel ausgibt (Item-ID + Name).

## Aufruf nach Installation

Nach Aktivierung des Plugins im gewünschten Plugin-Set ist die Liste erreichbar unter:

```
https://<dein-plenty-shop>/plugin/article-list-4711/articles
```

Der Aufruf läuft auf der Shop-Domain. Die Itemabfrage selbst läuft über `AuthHelper::processUnguarded`, damit die ACL-Prüfung intern mit Systemrechten auskommt.

## "Backend-Plugin" in Plentymarkets

Plenty unterscheidet im Wesentlichen zwei Plugin-Welten:

1. **Route-/Storefront-basierte Plugins** (was dieses Plugin ist) — registrieren eine URL über `Plenty\Plugin\Routing\Router`. Erreichbar unter der Shop-Domain. Schnell zu bauen, ideal für interne Admin-Tools, die per Bookmark aufgerufen werden.
2. **Echte Backend-UI-Plugins** — integrieren sich als Menüpunkt/Tab direkt in die Plenty-Adminoberfläche. Setzen das Plenty-eigene UI-Framework (Vue/TS, Container/MenuItem-Registrierung) voraus und sind deutlich aufwendiger.

Für "10 Artikel auflisten" reicht Variante 1. Wenn der Wunsch ist, dass die Liste **innerhalb** der Plenty-Adminoberfläche als Menüpunkt auftaucht, ist Variante 2 nötig — sag Bescheid, dann erweitere ich um die Menü-Registrierung.

## Open Source / Quellcode-Sichtbarkeit

Plentymarkets-Plugins werden als PHP-/Twig-Quelltext ausgeliefert. Im Backend unter **Plugins → Plugin-Übersicht → ArticleList4711 → Dateibrowser** sind alle Dateien einsehbar. Keine Kompilierung, keine Verschlüsselung. Eine Live-Ansicht des Codes aus dem Plugin heraus ist nicht möglich, weil der Plenty-PHP-Sandbox `file_get_contents`/`dirname` nicht erlaubt.

## Aufbau

```
plugin.json                                            Plugin-Manifest
src/Providers/ArticleList4711ServiceProvider.php       Registriert die Route (Konvention: <PluginName>ServiceProvider)
src/Controllers/ArticleListController.php              Holt 10 Items, übergibt sie an die View
resources/views/ArticleList.twig                       HTML-Tabelle
```

## Datenquelle

`Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract::search([], ['*'], [], 1, 10)` — liefert die erste Seite mit 10 Items inkl. `texts[]`. Der Name wird aus dem ersten verfügbaren `texts[].name1` gezogen.

Falls stattdessen Varianten oder ElasticSearch-basierte Suche gewünscht ist, lässt sich der Controller auf `VariationSearchRepositoryContract` bzw. `VariationElasticSearchSearchRepositoryContract` umstellen.

## Deployment

1. Plugin-Set in Plenty öffnen → Plugin via Git-URL hinzufügen.
2. Branch `main` wählen, installieren, ins Plugin-Set einbinden, deployen.
3. URL oben aufrufen.
