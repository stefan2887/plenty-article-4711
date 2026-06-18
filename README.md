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

**Zwei Routen:**

| Route | Zweck |
|---|---|
| `GET /rest/article-list-4711/external/articles` | Alle Artikel, paginiert |
| `GET /rest/article-list-4711/external/articles/by-marking/{storeSpecial}` | Artikel gefiltert nach Plenty-Standardmarkierung |

Beide brauchen den Header `X-Api-Key`, akzeptieren die gleichen Query-Parameter und liefern das gleiche Response-Schema (die Filter-Route fügt einen zusätzlichen `filter`-Block hinzu).

**API-Key setzen:** Plenty-Backend → **Plugins → Plugin-Übersicht → ArticleList4711 → Konfiguration**, Tab *Externer Zugriff*. Solange der Key leer ist, wird **jeder Aufruf mit 401 abgelehnt** (kein Default-Key).

**Query-Parameter (beide Routen):**

| Param | Default | Max | Beschreibung |
|---|---|---|---|
| `page` | `1` | — | 1-basierte Seite |
| `per_page` | `50` | `200` | Items pro Seite (hartes Maximum: 200) |
| `lang` | `de` | — | Bevorzugte Sprache für `primary_name`; `texts_by_lang` enthält alle verfügbaren Sprachen |

**Route-Parameter (nur `by-marking`):**

| Param | Wertebereich | Beschreibung |
|---|---|---|
| `storeSpecial` | int | Plenty-Standardmarkierung. `0` = keine, `1` = Schnäppchen, `2` = Neu, `3` = Top-Artikel, `4` = reduziert, `5` = Sonderpreis. (Werte entsprechen Plenty-Default; bei abweichender Shop-Konfiguration im Backend prüfen.) |

**Response-Schema (`schema_version: "1"`):**

```json
{
  "data": [
    {
      "id": 12345,
      "position": 0,
      "manufacturer_id": null,
      "stock_limitation": 0,
      "store_special": 3,
      "created_at": "2024-01-15T08:30:00+00:00",
      "updated_at": "2026-04-29T10:15:00+00:00",
      "primary_name": "Artikelname Deutsch",
      "texts_by_lang": {
        "de": {
          "name1": "Artikelname Deutsch",
          "name2": null,
          "name3": null,
          "description": "<p>HTML-Langbeschreibung…</p>",
          "short_description": "Kurzbeschreibung",
          "technical_data": null,
          "meta_keywords": null,
          "meta_description": null,
          "url_path": "artikelname-deutsch"
        }
      },
      "images": [
        {
          "id": 9876,
          "position": 0,
          "type": "internal",
          "file_type": "jpg",
          "path": "S3:9876:item-name.jpg",
          "url":         "https://<shop>/item/images/9876/0_source_item-name.jpg",
          "url_preview": "https://<shop>/item/images/9876/0_preview_item-name.jpg",
          "url_middle":  "https://<shop>/item/images/9876/0_middle_item-name.jpg"
        }
      ],
      "variations": [
        {
          "id": 1234,
          "item_id": 12345,
          "number": "SKU-12345-XL",
          "is_main": true,
          "is_active": true,
          "position": 0,
          "external_id": null,
          "model": null,
          "vat_id": 1,
          "weight_g": 250,
          "weight_net_g": 230,
          "width_mm": 100, "length_mm": 100, "height_mm": 50,
          "packing_units": 1,
          "packing_unit_type_id": null,
          "main_warehouse_id": 0,
          "picking": null,
          "stock_limitation": 0,
          "released_at": "2024-01-15T08:30:00+00:00",
          "available_until": null,
          "created_at": "2024-01-15T08:30:00+00:00",
          "updated_at": "2026-04-29T10:15:00+00:00",
          "prices":     [{ "sales_price_id": 1, "price": 19.99, "currency": "EUR", "updated_at": "..." }],
          "stock":      [{ "warehouse_id": 0,  "stock_net": 42, "physical_stock": 50, "reserved_stock": 8, "updated_at": "..." }],
          "barcodes":   [{ "barcode_id": 1, "code": "4006381333933", "created_at": "..." }],
          "categories": [{ "category_id": 12, "plenty_id": 0, "position": 0, "is_default": true }],
          "properties": [{ "property_id": 5, "value_int": null, "value_float": null, "value_string": "Edelstahl", "value_selection": null, "surcharge": null }],
          "clients":    [{ "plenty_id": 0 }],
          "markets":    [{ "market_id": "11.04", "referrer_id": "11.04", "referrer_name": "tiktok", "referrer_backend_name": "TikTok Krupsid", "sku": "...", "initial_sku": "..." }],
          "attribute_values": [{ "attribute_id": 1, "attribute_value_id": 7 }],
          "unit": { "unit_id": 1, "content": 1.0 },
          "ean": "4006381333933",
          "tiktok_brand_id": "7300000000000000000",
          "electronics_label_url": "https://<shop>/.../elektro-kennzeichnung.pdf"
        }
      ],
      "referrers": [{ "id": "11.04", "name": "tiktok", "backend_name": "TikTok Krupsid" }]
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
    "with_item":      ["texts", "itemImages"],
    "with_variation": ["variationSalesPrices", "variationProperties", "variationBarcodes",
                       "variationCategories", "variationClients", "variationMarkets",
                       "variationAttributeValues", "unit", "stock"],
    "schema_version": "3"
  }
}
```

**Wie Variations geladen werden (seit v1.9.0):** Items und Variations kommen aus zwei getrennten Plenty-Repos:
1. **Items** über `ItemRepositoryContract::search()` mit minimalem with (`texts`, `itemImages`) — das definiert die Pagination (`page`/`per_page` = Items).
2. **Variations** für die geladenen Item-IDs über `VariationSearchRepositoryContract::search(['itemIds' => …])` mit vollem with (Preise, Properties, Barcodes, Kategorien, etc.).

Pro Request also genau zwei Plenty-API-Calls. Vorteil: das Variation-Repo löst die Sub-Relations zuverlässig eager auf, anders als das Item-Repo. Konsumenten-Vertrag (`page`/`per_page`/`has_next_page`) bleibt item-basiert wie zuvor.

**Dedizierte Zusatzfelder (pro Variante, seit v1.12.0):** Zusätzlich zu den generischen Listen `barcodes[]` und `properties[]` hebt der Export drei häufig gebrauchte Werte direkt heraus. Sie sind `null`, wenn die Quelle für die Variante nicht gesetzt ist.

| Feld | Quelle | Bemerkung |
|---|---|---|
| `ean` | Barcode mit `barcode_id == 1` | `code` dieses Barcodes, als String (führende Nullen/Länge bleiben erhalten). |
| `tiktok_brand_id` | Eigenschaft `property_id == 121` | `value_string` (Texttyp). **Bewusst String** — TikTok-Marken-IDs sind lange numerische Werte, die als Zahl Präzision verlieren würden. |
| `electronics_label_url` | Eigenschaft `property_id == 122` | `value_string` (Datei-Eigenschaft, erwartet einen Link/URL zur PDF der Elektrogeräte-Kennzeichnung). |

⚠️ **Wichtig zur Datenquelle:** Diese Felder lesen aus der klassischen `variationProperties`-Relation (= das, was schon in `properties[]` erscheint). Falls 121/122 als **neue Plenty-„Eigenschaften" (Properties 2.0 / Merkmale)** angelegt wurden, laufen sie über eine *andere* Relation und tauchen weder in `properties[]` noch in diesen Feldern auf — dann ist ein zusätzlicher Repository-Load nötig.

**So prüfst du in 1 Minute, welcher Fall vorliegt** (Variante mit gesetzten Werten verwenden):
```powershell
$h = @{ "X-Api-Key" = "<key>" }
$r = Invoke-RestMethod "https://<shop>/rest/article-list-4711/external/articles?per_page=200" -Headers $h
$r.data.variations.properties | Where-Object { $_.property_id -in 121,122 }
```
Kommt hier etwas zurück → klassische Eigenschaften, die neuen Felder funktionieren direkt. Bleibt es leer → neue „Eigenschaften", bitte melden (dann ergänze ich die passende Relation).

Bei `by-marking` kommen zwei zusätzliche Felder dazu — alles andere ist identisch:

```json
{
  "filter": { "store_special": 3 },
  "meta":   { "...": "...", "filter_applied": "post_load_php" }
}
```

`filter_applied: "post_load_php"` signalisiert, dass der Filter **nach** dem Plenty-Load im PHP angewandt wird. Konsequenzen:
- `pagination.total_count` ist die ungefilterte Plenty-Summe, nicht die Anzahl der Treffer.
- `pagination.returned_count` zeigt die Treffer **dieser Seite** nach Filter.
- Für ein vollständiges gefiltertes Set: Loop bis `has_next_page == false`, client-seitig sammeln. `per_page=200` empfohlen, um die Round-Trips zu reduzieren.

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
- Schema-Inkrement (`schema_version`) bei brechenden Änderungen. Additive Felder (neue Keys in `data[]` oder `meta`) sind kein Inkrement-Anlass — der Konsument sollte unbekannte Keys tolerant ignorieren.

## Externer Order-Endpoint (POST)

Anlage einer Plenty-Bestellung aus externem System (Shop, Marketplace, ERP).

**Route:** `POST /rest/article-list-4711/external/orders`
**Auth:** `X-Api-Key` (gleicher Key wie beim Artikel-Export)
**Content-Type:** `application/json`

**Plenty vergibt alle neuen IDs selbst** — Caller schickt keine Plenty-Order-, Address- oder Payment-IDs mit. Plenty-Konfigurations-IDs (plenty_id, status_id, payment_method_id, …) muss der Caller kennen oder die Plugin-Defaults aus der Konfiguration greifen lassen (siehe Tab *Order-Defaults*).

**Idempotency:** Wenn der Caller `external_order_id` mitschickt, prüft das Plugin via `OrderRepositoryContract::findOrderByExternalOrderId()` vor dem Anlegen. Bei Treffer wird die bestehende Order zurückgegeben (`created: false`), ohne eine zweite anzulegen. Ohne `external_order_id` legt jeder POST eine neue Order an — Duplikat-Schutz liegt beim Caller.

**Request-Body:**

```json
{
  "external_order_id": "shop-12345",
  "referrer_id": 11.04,
  "plenty_id": 1,
  "status_id": 5.0,
  "type_id": 1,
  "owner_id": 0,
  "warehouse_id": 104,
  "shipping_profile_id": 6,
  "customer_sign": "TikTok-Order",
  "items": [
    {
      "variation_id": 1234,
      "quantity": 2,
      "unit_price": 19.99,
      "vat_rate": 19,
      "country_vat_id": 1,
      "vat_field": 0,
      "currency": "EUR",
      "name": "Produktname"
    }
  ],
  "billing_address": {
    "gender": "male",
    "company": "",
    "first_name": "Max",
    "last_name": "Mustermann",
    "street": "Musterstraße",
    "house_no": "1",
    "address_addition": "",
    "postal_code": "12345",
    "town": "Musterstadt",
    "country_id": 1,
    "state_id": null,
    "email": "max@example.com",
    "phone": "0123456789"
  },
  "shipping_address": { "...wie billing_address..." },
  "payment": {
    "method_id": 1,
    "amount": 39.98,
    "currency": "EUR",
    "status": 2,
    "transaction_type": 2,
    "transaction_id": "tx-abc-123",
    "received_at": "2026-05-22T10:00:00+00:00"
  }
}
```

**Pflichtfelder:** `items[]` (mind. 1, mit `variation_id`, `quantity`, `unit_price`), `billing_address` (`first_name`, `last_name`, `street`, `house_no`, `postal_code`, `town`, `country_id`).

**Optional:** `external_order_id` (empfohlen für Idempotency), `shipping_address` (fällt sonst auf `billing_address` zurück), `payment` (Payment-Anlage + Verknüpfung zur Order), `warehouse_id` / `shipping_profile_id` / `customer_sign` (werden als Plenty-Order-Properties abgelegt), alle Plenty-Config-IDs (Defaults aus Plugin-Config).

**Response (Erfolg, HTTP 201):**

```json
{
  "created": true,
  "order": {
    "plenty_order_id": 4711,
    "external_order_id": "shop-12345",
    "billing_address_id": 9999,
    "shipping_address_id": 9999,
    "payment_id": 1234
  },
  "meta": {
    "fetched_at": "2026-05-22T14:32:00+00:00",
    "endpoint": "/rest/article-list-4711/external/orders",
    "schema_version": "1"
  }
}
```

**Response (Duplikat, HTTP 200):**

```json
{
  "created": false,
  "reason": "duplicate_external_order_id",
  "order": { "plenty_order_id": 4711, "external_order_id": "shop-12345" },
  "meta": { "..." : "..." }
}
```

**Response (Validation-Fehler, HTTP 422):**

```json
{ "error": { "code": "validation_failed", "message": "items[0].variation_id fehlt." } }
```

**Response (Plenty-Fehler beim Anlegen, HTTP 500):**

```json
{ "error": { "code": "order_create_failed", "message": "<Plenty-Exception-Text>" } }
```

**Teil-Erfolg (Order angelegt, Payment fehlgeschlagen, HTTP 201):** Die Order bleibt — das Payment-Failure landet in `warnings[]`, der Caller kann den Payment-POST später nachholen.

**Plenty-IDs, die der Caller kennen muss** (oder per Plugin-Default vorbelegt):
- `plenty_id` — Plenty-Mandanten-ID (typisch `1`)
- `status_id` — Order-Status (`5.0` = Freigegeben in Plenty-Default; bei Custom-Status-Config im Backend prüfen)
- `referrer_id` — Herkunft, Float (`1` = Webshop, `11.04` = Custom-Channel). **Gleicher ID-Raum wie `variationMarkets.marketId` im Artikel-Export.**
- `country_id` — ISO-Plenty-Land-ID (1 = DE, 2 = AT, …)
- `payment.method_id` — Plenty-Zahlungsart-ID (1 = Vorkasse, 2 = Nachnahme, …; shop-spezifisch konfiguriert)
- `payment.status` — 2 = Captured/Bezahlt, 1 = Pending, …
- `warehouse_id`, `shipping_profile_id` — shop-spezifische IDs

**Plenty-Order-Property-Type-IDs** (Quelle: `Plenty\Modules\Order\Property\Models\OrderPropertyType`):
| Type | Bedeutung | Plugin-Mapping |
|---|---|---|
| 1 | WAREHOUSE | `warehouse_id` |
| 2 | SHIPPING_PROFILE | `shipping_profile_id` |
| 3 | PAYMENT_METHOD | `payment.method_id` |
| 7 | EXTERNAL_ORDER_ID | `external_order_id` |
| 8 | CUSTOMER_SIGN | `customer_sign` |

## Externer Order-Update-Endpoint (PUT)

Teil-Update einer bestehenden Plenty-Bestellung. Bewusst eng gehalten: **nur Status und Zahlungen** — Bestellpositionen und Adressen werden hier *nicht* geändert.

**Route:** `PUT /rest/article-list-4711/external/orders/{orderId}`
**Auth:** `X-Api-Key` (gleicher Key wie sonst)
**Content-Type:** `application/json`
**`{orderId}`:** die Plenty-Order-ID (`plenty_order_id` aus der Anlage-Response).

**Request-Body** (mindestens eines von `status_id` oder `payment`/`payments`):

```json
{
  "status_id": 7.0,
  "payments": [
    {
      "method_id": 1,
      "amount": 39.98,
      "currency": "EUR",
      "status": 2,
      "transaction_type": 2,
      "transaction_id": "tx-abc-123",
      "received_at": "2026-05-29T10:00:00+00:00"
    }
  ]
}
```

- **`status_id`** (optional) — neuer Order-Status via `OrderRepositoryContract::updateOrder(['statusId' => …], orderId)`. Partielles Update: Items, Adressen und Properties bleiben unangetastet.
- **`payments`** (optional, Array) **oder** `payment` (optional, Einzelobjekt) — legt je Eintrag eine Zahlung an und verknüpft sie mit der Order (gleiche Logik wie `payment` bei der Anlage). `payments` hat Vorrang, wenn beide gesetzt sind. Pflicht je Zahlung: `method_id`, `amount`.

**Nicht abgedeckt (bewusst):** Bestellpositionen ändern, Adressen ändern, eine *bereits existierende* Zahlung mutieren. Zahlungen werden hier ausschließlich **additiv** angelegt.

**⚠️ Idempotenz:** Ein erneutes PUT mit demselben `payment` legt eine **zweite** Zahlung an. Pro Zahlung eine eindeutige `transaction_id` mitgeben und nur bei echten Netzwerkfehlern erneut senden. Das Status-Update selbst ist idempotent.

**Response (Erfolg, HTTP 200):**

```json
{
  "updated": true,
  "order": {
    "plenty_order_id": 4711,
    "applied": {
      "status_id": 7.0,
      "payment_ids": [1234]
    }
  },
  "meta": {
    "fetched_at": "2026-05-29T14:32:00+00:00",
    "endpoint": "/rest/article-list-4711/external/orders",
    "schema_version": "1"
  }
}
```

**Response (Order nicht gefunden, HTTP 404):**

```json
{ "error": { "code": "order_not_found", "message": "Order 4711 existiert nicht." } }
```

**Response (leeres/ungültiges Update, HTTP 422):**

```json
{ "error": { "code": "validation_failed", "message": "Nichts zu aktualisieren: mindestens `status_id` oder `payment`/`payments` angeben." } }
```

**Teil-Erfolg (Status gesetzt, Payment fehlgeschlagen, HTTP 200):** Das Status-Update bleibt bestehen; fehlgeschlagene Zahlungen landen in `warnings[]` (`code: payment_create_failed`, mit `index`), erfolgreiche in `applied.payment_ids`.

## Aufbau

```
plugin.json                                            Plugin-Manifest (type: backend)
config.json                                            Plugin-Konfiguration (API-Key, Order-Defaults)
ui.json                                                Backend-Menüeintrag
ui/index.html                                          Backend-View (Iframe-Inhalt)
src/Providers/ArticleList4711ServiceProvider.php       Router + ApiRouter
src/Controllers/ArticleListController.php              Twig-Route (HTML)
src/Controllers/ArticleListApiController.php           Backend-REST (JSON, Session-Auth)
src/Controllers/ExternalArticleController.php          Externer Artikel-Export (GET, X-Api-Key)
src/Controllers/ExternalOrderController.php            Externer Order-Endpoint (POST, X-Api-Key)
src/Controllers/ArticleListLoader.php                  Geteilter Item-Loader
resources/views/ArticleList.twig                       HTML-Tabelle für die Twig-Route
```

`plugin.json` muss `"type": "backend"` setzen — nur damit serviert Plenty die `ui/`-Assets unter dem internen Iframe-Pfad. Mit `"type": "plugin"` wird die `ui.json` zwar gelesen (Menüeintrag erscheint), die View-Datei aber nicht ausgeliefert (404 im iframe).

## Datenquelle

Zwei Plenty-Repos, ein Request:

1. **`Plenty\Modules\Item\Item\Contracts\ItemRepositoryContract::search([], [$lang], $page, $perPage, ['texts','itemImages'])`** — paginiert Items, lädt die Texte und Bilder eager. Pagination-Vertrag (`page`/`per_page`) ist item-basiert und bleibt stabil. Konfiguriert in `ExternalArticleController::itemRelations()`.

2. **`Plenty\Modules\Item\Variation\Contracts\VariationSearchRepositoryContract`** — bekommt die in Schritt 1 geladenen `itemIds` als Filter und lädt für genau diese Items alle Variations + Sub-Relations (Preise, Properties, Barcodes, Kategorien, Clients, Markets, Unit, Stock, AttributeValues). Konfiguriert in `ExternalArticleController::variationRelations()`. Hintergrund: Plenty's Item-Repo löst die Variation-Sub-Relations über `variations.*`-Punkt-Notation nur sehr selektiv eager auf (Preise/Properties/Markets blieben in v1.8.0 konsistent leer), das Variation-Repo dagegen ist da zuverlässig.

Beide Calls laufen in `AuthHelper::processUnguarded`, das Ergebnis aus Schritt 2 wird nach `itemId` gruppiert und beim Serialize an `serializeArticle($item, $lang, $preloadedVariations)` durchgereicht.

Der externe `by-marking`-Endpoint filtert **nach** dem Plenty-Load im PHP, weil `ItemRepositoryContract::search()` keinen `storeSpecial`-Filter unterstützt. Bei wachsender Datenmenge (>>1000 Items) wäre der Umstieg auf `VariationElasticSearchSearchRepositoryContract` mit echtem Server-Side-Filter sinnvoll.

## Hinweise zur Plenty-Sandbox

Plenty validiert PHP-Plugins gegen eine Funktions-Allowlist — `file_get_contents`, `dirname`, etc. sind nicht erlaubt. Pfad-/Dateioperationen sollten ausschließlich über die offiziellen SDK-Services laufen.

## Deployment

1. Plugin-Set in Plenty öffnen → Plugin via Git-URL hinzufügen.
2. Branch `main` wählen, installieren, ins Plugin-Set einbinden, deployen.
3. Backend neu laden — der Menüeintrag erscheint unter **Artikel → Artikelliste 4711**.
