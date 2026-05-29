<?php

namespace ArticleList4711\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Authorization\Services\AuthHelper;

/**
 * POST-Endpoint zum Anlegen einer Plenty-Bestellung aus externem System.
 *
 * Endpoint:  POST /rest/article-list-4711/external/orders
 * Auth:      X-Api-Key Header (gleicher Key wie der Artikel-Export)
 * Body:      JSON, siehe README → "Externer Order-Endpoint"
 *
 * Architektur:
 *   1. Auth via X-Api-Key
 *   2. Idempotency-Check über `external_order_id` (optional, aber empfohlen)
 *      — `OrderRepositoryContract::findOrderByExternalOrderId()` ist first-class
 *      in Plenty; bei Duplikat wird die bestehende Order zurückgegeben statt
 *      eine zweite anzulegen.
 *   3. Adressen anlegen via AddressRepositoryContract::createAddress()
 *      — Rechnung (typeId 1) + Lieferung (typeId 2, optional, fällt auf
 *      Rechnungsadresse zurück).
 *   4. Order anlegen via OrderRepositoryContract::createOrder() mit
 *      eingebetteten orderItems, addressRelations, properties.
 *   5. Optional: Payment anlegen via PaymentRepositoryContract + Verknüpfung
 *      via PaymentOrderRelationRepositoryContract::createOrderRelationWithValidation().
 *
 * Plenty vergibt alle IDs (order_id, address_id, payment_id) selbst — der
 * Caller schickt keine Plenty-IDs für neue Entitäten mit. Plenty-Config-IDs
 * (plenty_id, status_id, payment_method_id, …) muss der Caller dagegen kennen
 * oder die Plugin-Defaults aus der Config greifen lassen.
 *
 * Order-Property-Type-IDs (aus Plenty\Modules\Order\Property\Models\OrderPropertyType):
 *   1 = WAREHOUSE, 2 = SHIPPING_PROFILE, 3 = PAYMENT_METHOD, 4 = PAYMENT_STATUS,
 *   7 = EXTERNAL_ORDER_ID, 8 = CUSTOMER_SIGN
 *   (Achtung: `referrer` ist KEIN Property — das ist Top-Level `referrerId`.)
 */
class ExternalOrderController extends Controller
{
    /** Schema-Version des Request-/Response-Envelopes. */
    const SCHEMA_VERSION = '1';

    /** Plenty Order-Property-Type-IDs (verbatim aus dem Plenty-Modell). */
    const PROP_WAREHOUSE         = 1;
    const PROP_SHIPPING_PROFILE  = 2;
    const PROP_PAYMENT_METHOD    = 3;
    const PROP_EXTERNAL_ORDER_ID = 7;
    const PROP_CUSTOMER_SIGN     = 8;

    /** Plenty AddressRelationType (Order → Address): 1 = Rechnung, 2 = Lieferung. */
    const ADDR_REL_INVOICE  = 1;
    const ADDR_REL_DELIVERY = 2;

    /**
     * POST /rest/article-list-4711/external/orders
     */
    public function create(
        Request $request,
        Response $response,
        ConfigRepository $config,
        OrderRepositoryContract $orderRepository,
        AddressRepositoryContract $addressRepository,
        PaymentRepositoryContract $paymentRepository,
        PaymentOrderRelationRepositoryContract $paymentOrderRelation,
        AuthHelper $authHelper
    ) {
        $authErr = self::requireValidApiKey($request, $response, $config);
        if ($authErr !== null) return $authErr;

        $payload = self::readJsonBody($request);
        if (!is_array($payload)) {
            return self::jsonError($response, $request, 'invalid_body',
                'Request-Body muss valides JSON-Objekt sein.', 400);
        }

        $validationErr = self::validatePayload($payload);
        if ($validationErr !== null) {
            return self::jsonError($response, $request, 'validation_failed',
                $validationErr, 422);
        }

        // ---- 1. Idempotency-Check ----------------------------------------
        $externalOrderId = isset($payload['external_order_id'])
            ? (string) $payload['external_order_id']
            : null;
        if ($externalOrderId !== null && $externalOrderId !== '') {
            $existing = self::findExistingOrder($authHelper, $orderRepository, $externalOrderId);
            if ($existing !== null) {
                return $response->json([
                    'created'      => false,
                    'reason'       => 'duplicate_external_order_id',
                    'order'        => self::serializeOrderSummary($existing, $externalOrderId),
                    'meta'         => self::baseMeta($request),
                ], 200);
            }
        }

        // ---- 2. Adressen anlegen -----------------------------------------
        $billingAddrId  = null;
        $shippingAddrId = null;
        try {
            $billingAddrId = self::createAddress(
                $authHelper, $addressRepository, $payload['billing_address']
            );
            if (!empty($payload['shipping_address'])) {
                $shippingAddrId = self::createAddress(
                    $authHelper, $addressRepository, $payload['shipping_address']
                );
            } else {
                // Lieferadresse fällt auf Rechnungsadresse zurück
                $shippingAddrId = $billingAddrId;
            }
        } catch (\Throwable $e) {
            return self::jsonError($response, $request, 'address_create_failed',
                'Adress-Anlage fehlgeschlagen: ' . $e->getMessage(), 500);
        }

        // ---- 3. Order-Payload bauen + Order anlegen ----------------------
        $orderData = self::buildOrderData($payload, $config, $billingAddrId, $shippingAddrId, $externalOrderId);

        try {
            $order = $authHelper->processUnguarded(function () use ($orderRepository, $orderData) {
                return $orderRepository->createOrder($orderData);
            });
        } catch (\Throwable $e) {
            return self::jsonError($response, $request, 'order_create_failed',
                'Order-Anlage in Plenty fehlgeschlagen: ' . $e->getMessage(), 500);
        }

        $orderId = self::asInt(self::prop($order, 'id'));
        if ($orderId === null) {
            return self::jsonError($response, $request, 'order_create_no_id',
                'Plenty hat eine Order angelegt, aber keine ID zurückgegeben.', 500);
        }

        // ---- 4. Optional: Payment anlegen + verknüpfen -------------------
        $paymentId = null;
        if (!empty($payload['payment'])) {
            try {
                $paymentId = self::createAndLinkPayment(
                    $authHelper, $paymentRepository, $paymentOrderRelation,
                    $payload['payment'], $orderId
                );
            } catch (\Throwable $e) {
                // Order ist schon angelegt — Payment-Fehler soll nicht die ganze
                // Order verwerfen. Wir loggen es in der Response statt zu rollen.
                return $response->json([
                    'created'   => true,
                    'order'     => [
                        'plenty_order_id'     => $orderId,
                        'external_order_id'   => $externalOrderId,
                        'billing_address_id'  => $billingAddrId,
                        'shipping_address_id' => $shippingAddrId,
                        'payment_id'          => null,
                    ],
                    'warnings'  => [
                        ['code' => 'payment_create_failed', 'message' => $e->getMessage()],
                    ],
                    'meta'      => self::baseMeta($request),
                ], 201);
            }
        }

        return $response->json([
            'created'   => true,
            'order'     => [
                'plenty_order_id'     => $orderId,
                'external_order_id'   => $externalOrderId,
                'billing_address_id'  => $billingAddrId,
                'shipping_address_id' => $shippingAddrId,
                'payment_id'          => $paymentId,
            ],
            'meta'      => self::baseMeta($request),
        ], 201);
    }

    /**
     * PUT /rest/article-list-4711/external/orders/{orderId}
     *
     * Teil-Update einer bestehenden Plenty-Order. Bewusst eng gehalten:
     *   - `status_id`            → setzt den Order-Status via updateOrder (partiell,
     *                              rührt Items/Adressen/Properties nicht an).
     *   - `payment` / `payments` → legt eine ODER mehrere Zahlungen an und verknüpft
     *                              sie mit der Order (gleiche Logik wie bei create()).
     *
     * NICHT abgedeckt (bewusst): Bestellpositionen ändern und das Mutieren einer
     * bereits existierenden Zahlung. Neue Zahlungen werden additiv angelegt.
     *
     * Idempotenz-Hinweis: Ein erneutes PUT mit demselben `payment` legt eine
     * ZWEITE Zahlung an. Der Caller sollte je Zahlung eine eindeutige
     * `transaction_id` mitgeben und nur bei echten Netzwerkfehlern erneut senden.
     */
    public function update(
        Request $request,
        Response $response,
        ConfigRepository $config,
        OrderRepositoryContract $orderRepository,
        PaymentRepositoryContract $paymentRepository,
        PaymentOrderRelationRepositoryContract $paymentOrderRelation,
        AuthHelper $authHelper,
        int $orderId
    ) {
        $authErr = self::requireValidApiKey($request, $response, $config);
        if ($authErr !== null) return $authErr;

        $payload = self::readJsonBody($request);
        if (!is_array($payload)) {
            return self::jsonError($response, $request, 'invalid_body',
                'Request-Body muss valides JSON-Objekt sein.', 400);
        }

        $validationErr = self::validateUpdatePayload($payload);
        if ($validationErr !== null) {
            return self::jsonError($response, $request, 'validation_failed',
                $validationErr, 422);
        }

        // ---- 1. Order muss existieren ------------------------------------
        $existing = self::findOrderById($authHelper, $orderRepository, $orderId);
        if ($existing === null) {
            return self::jsonError($response, $request, 'order_not_found',
                "Order $orderId existiert nicht.", 404);
        }

        $applied  = [];
        $warnings = [];

        // ---- 2. Status-Update --------------------------------------------
        if (isset($payload['status_id']) && $payload['status_id'] !== '') {
            $newStatus = (float) $payload['status_id'];
            try {
                $authHelper->processUnguarded(function () use ($orderRepository, $orderId, $newStatus) {
                    return $orderRepository->updateOrder(['statusId' => $newStatus], $orderId);
                });
                $applied['status_id'] = $newStatus;
            } catch (\Throwable $e) {
                return self::jsonError($response, $request, 'status_update_failed',
                    'Status-Update in Plenty fehlgeschlagen: ' . $e->getMessage(), 500);
            }
        }

        // ---- 3. Zahlungen anlegen + verknüpfen ---------------------------
        $paymentIds = [];
        foreach (self::normalizePaymentsInput($payload) as $i => $pay) {
            try {
                $paymentIds[] = self::createAndLinkPayment(
                    $authHelper, $paymentRepository, $paymentOrderRelation, $pay, $orderId
                );
            } catch (\Throwable $e) {
                $warnings[] = [
                    'code'    => 'payment_create_failed',
                    'index'   => $i,
                    'message' => $e->getMessage(),
                ];
            }
        }
        if (!empty($paymentIds)) {
            $applied['payment_ids'] = $paymentIds;
        }

        $result = [
            'updated' => true,
            'order'   => [
                'plenty_order_id' => $orderId,
                'applied'         => $applied,
            ],
            'meta'    => self::baseMeta($request),
        ];
        if (!empty($warnings)) {
            $result['warnings'] = $warnings;
        }
        return $response->json($result, 200);
    }

    // ==================================================================
    // Validation
    // ==================================================================

    /**
     * Validiert den Top-Level-Payload. Gibt `null` bei OK zurück, sonst eine
     * konkrete Fehlermeldung (String).
     *
     * Geprüft wird das Minimum, das Plenty's `createOrder` braucht ohne uns mit
     * generischen Plenty-Exceptions zuzuwerfen. Tiefer-gehende Konsistenz
     * (variation_id existiert, status_id ist konfiguriert, ...) bleibt Plenty
     * überlassen — wir reichen die Plenty-Fehlermeldung in der Response durch.
     */
    private static function validatePayload(array $p): ?string
    {
        if (empty($p['items']) || !is_array($p['items'])) {
            return '`items` fehlt oder ist kein Array.';
        }
        foreach ($p['items'] as $i => $item) {
            if (!is_array($item)) {
                return "items[$i] ist kein Objekt.";
            }
            if (empty($item['variation_id'])) {
                return "items[$i].variation_id fehlt.";
            }
            if (!isset($item['quantity']) || (float) $item['quantity'] <= 0) {
                return "items[$i].quantity muss > 0 sein.";
            }
            if (!isset($item['unit_price'])) {
                return "items[$i].unit_price fehlt.";
            }
        }

        if (empty($p['billing_address']) || !is_array($p['billing_address'])) {
            return '`billing_address` fehlt oder ist kein Objekt.';
        }
        $requiredAddrFields = ['first_name', 'last_name', 'street', 'house_no', 'postal_code', 'town', 'country_id'];
        foreach ($requiredAddrFields as $f) {
            if (!isset($p['billing_address'][$f]) || $p['billing_address'][$f] === '') {
                return "billing_address.$f fehlt.";
            }
        }

        if (!empty($p['shipping_address'])) {
            if (!is_array($p['shipping_address'])) {
                return '`shipping_address` muss Objekt sein (oder weglassen).';
            }
            foreach ($requiredAddrFields as $f) {
                if (!isset($p['shipping_address'][$f]) || $p['shipping_address'][$f] === '') {
                    return "shipping_address.$f fehlt.";
                }
            }
        }

        if (!empty($p['payment'])) {
            if (!is_array($p['payment'])) {
                return '`payment` muss Objekt sein (oder weglassen).';
            }
            if (empty($p['payment']['method_id'])) {
                return 'payment.method_id fehlt.';
            }
            if (!isset($p['payment']['amount'])) {
                return 'payment.amount fehlt.';
            }
        }

        return null;
    }

    /**
     * Validiert den Update-Payload (PUT). Mindestens eines von `status_id`
     * oder `payment`/`payments` muss vorhanden sein — ein leeres Update wird
     * abgelehnt, damit ein versehentlich leerer Body nicht still 200 liefert.
     */
    private static function validateUpdatePayload(array $p): ?string
    {
        $hasStatus = isset($p['status_id']) && $p['status_id'] !== '';
        $payments  = self::normalizePaymentsInput($p);
        $hasPayments = !empty($payments);

        if (!$hasStatus && !$hasPayments) {
            return 'Nichts zu aktualisieren: mindestens `status_id` oder `payment`/`payments` angeben.';
        }
        if ($hasStatus && (float) $p['status_id'] <= 0) {
            return '`status_id` muss > 0 sein.';
        }
        foreach ($payments as $i => $pay) {
            if (!is_array($pay)) {
                return "payments[$i] ist kein Objekt.";
            }
            if (empty($pay['method_id'])) {
                return "payments[$i].method_id fehlt.";
            }
            if (!isset($pay['amount'])) {
                return "payments[$i].amount fehlt.";
            }
        }
        return null;
    }

    /**
     * Normalisiert die Zahlungs-Eingabe zu einer Liste. Akzeptiert `payments`
     * (Array von Objekten) ODER `payment` (Einzelobjekt, wie bei create()).
     * `payments` hat Vorrang, wenn beide gesetzt sind.
     */
    private static function normalizePaymentsInput(array $p): array
    {
        if (!empty($p['payments']) && is_array($p['payments'])) {
            return array_values($p['payments']);
        }
        if (!empty($p['payment']) && is_array($p['payment'])) {
            return [$p['payment']];
        }
        return [];
    }

    // ==================================================================
    // Idempotency
    // ==================================================================

    /**
     * Sucht eine Plenty-Order mit der gegebenen externalOrderId. Plenty's
     * `findOrderByExternalOrderId` wirft, wenn keine Order existiert — wir
     * fangen das ab und liefern `null` für "nicht gefunden".
     */
    private static function findExistingOrder(
        AuthHelper $authHelper,
        OrderRepositoryContract $orderRepository,
        string $externalOrderId
    ) {
        try {
            return $authHelper->processUnguarded(function () use ($orderRepository, $externalOrderId) {
                return $orderRepository->findOrderByExternalOrderId($externalOrderId);
            });
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Lädt eine Order per Plenty-ID. `findOrderById` wirft, wenn keine Order
     * existiert — wir fangen das ab und liefern `null` für "nicht gefunden",
     * damit der Update-Endpoint einen sauberen 404 statt eines 500 liefert.
     */
    private static function findOrderById(
        AuthHelper $authHelper,
        OrderRepositoryContract $orderRepository,
        int $orderId
    ) {
        try {
            return $authHelper->processUnguarded(function () use ($orderRepository, $orderId) {
                return $orderRepository->findOrderById($orderId);
            });
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ==================================================================
    // Address-Anlage
    // ==================================================================

    /**
     * Legt eine Plenty-Adresse an und gibt die vergebene addressId zurück.
     * Mapping vom externen Schema (snake_case, semantische Namen) auf das
     * Plenty-AddressRepository-Schema (gemischt camel/snake, Plenty-Quirk).
     */
    private static function createAddress(
        AuthHelper $authHelper,
        AddressRepositoryContract $addressRepository,
        array $a
    ): int {
        $data = [
            'gender'      => isset($a['gender'])      ? (string) $a['gender']      : null,
            'name1'       => isset($a['company'])     ? (string) $a['company']     : '',
            'name2'       => (string) $a['first_name'],
            'name3'       => (string) $a['last_name'],
            'address1'    => (string) $a['street'],
            'address2'    => (string) $a['house_no'],
            'address3'    => isset($a['address_addition']) ? (string) $a['address_addition'] : '',
            'postalCode'  => (string) $a['postal_code'],
            'town'        => (string) $a['town'],
            'countryId'   => (int)    $a['country_id'],
            'stateId'     => isset($a['state_id']) ? (int) $a['state_id'] : null,
            'phoneNumber' => isset($a['phone']) ? (string) $a['phone'] : null,
            'email'       => isset($a['email']) ? (string) $a['email'] : null,
        ];

        $created = $authHelper->processUnguarded(function () use ($addressRepository, $data) {
            return $addressRepository->createAddress($data);
        });

        $id = self::asInt(self::prop($created, 'id'));
        if ($id === null) {
            throw new \RuntimeException('Plenty hat eine Adresse angelegt, aber keine ID zurückgegeben.');
        }
        return $id;
    }

    // ==================================================================
    // Order-Daten bauen
    // ==================================================================

    /**
     * Baut das Plenty-createOrder()-Datenarray aus dem externen Payload.
     * Defaults für Plenty-Config-IDs kommen aus der Plugin-Config; Caller-Felder
     * überschreiben sie.
     */
    private static function buildOrderData(
        array $p,
        ConfigRepository $config,
        int $billingAddrId,
        int $shippingAddrId,
        ?string $externalOrderId
    ): array {
        $typeId      = isset($p['type_id'])     ? (int)   $p['type_id']     : (int)   $config->get('ArticleList4711.default_order_type_id', '1');
        $plentyId    = isset($p['plenty_id'])   ? (int)   $p['plenty_id']   : (int)   $config->get('ArticleList4711.default_plenty_id', '1');
        $statusId    = isset($p['status_id'])   ? (float) $p['status_id']   : (float) $config->get('ArticleList4711.default_order_status_id', '5.0');
        $referrerId  = isset($p['referrer_id']) ? (float) $p['referrer_id'] : (float) $config->get('ArticleList4711.default_referrer_id', '1');
        $ownerId     = isset($p['owner_id'])    ? (int)   $p['owner_id']    : 0;

        $data = [
            'typeId'     => $typeId,
            'plentyId'   => $plentyId,
            'statusId'   => $statusId,
            'referrerId' => $referrerId,
            'ownerId'    => $ownerId,
            'orderItems' => self::buildOrderItems($p['items']),
            'addressRelations' => [
                ['typeId' => self::ADDR_REL_INVOICE,  'addressId' => $billingAddrId],
                ['typeId' => self::ADDR_REL_DELIVERY, 'addressId' => $shippingAddrId],
            ],
            'properties' => self::buildOrderProperties($p, $externalOrderId),
        ];

        return $data;
    }

    /**
     * Konvertiert externe Item-Liste zu Plenty's `orderItems`-Format.
     *
     * Plenty erwartet OrderItem-typeId 1 für Variation-basierte Positionen
     * (= klassischer Artikel-Verkauf). Preis kommt in `amounts[]`, nicht als
     * Top-Level `price` — das ist eine Plenty-Eigenheit.
     */
    private static function buildOrderItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $variationId = (int) $item['variation_id'];
            $quantity    = (float) $item['quantity'];
            $unitPrice   = (float) $item['unit_price'];
            $vatRate     = isset($item['vat_rate'])      ? (float) $item['vat_rate']     : 19.0;
            $vatField    = isset($item['vat_field'])     ? (int)   $item['vat_field']    : 0;
            $countryVatId= isset($item['country_vat_id']) ? (int)  $item['country_vat_id'] : 1;
            $name        = isset($item['name']) ? (string) $item['name'] : '';

            $out[] = [
                'typeId'         => 1,  // 1 = Variation/Sales position
                'itemVariationId'=> $variationId,
                'quantity'       => $quantity,
                'orderItemName'  => $name,
                'countryVatId'   => $countryVatId,
                'vatField'       => $vatField,
                'vatRate'        => $vatRate,
                'amounts'        => [[
                    'isSystemCurrency' => true,
                    'isNet'            => false,
                    'currency'         => isset($item['currency']) ? (string) $item['currency'] : 'EUR',
                    'exchangeRate'     => 1,
                    'priceOriginalGross' => $unitPrice,
                ]],
            ];
        }
        return $out;
    }

    /**
     * Baut die Order-Properties-Liste. Plenty erwartet `[{typeId, value}, ...]`
     * mit Type-IDs aus OrderPropertyType (Konstanten oben an der Klasse).
     */
    private static function buildOrderProperties(array $p, ?string $externalOrderId): array
    {
        $out = [];
        if ($externalOrderId !== null && $externalOrderId !== '') {
            $out[] = ['typeId' => self::PROP_EXTERNAL_ORDER_ID, 'value' => $externalOrderId];
        }
        if (!empty($p['warehouse_id'])) {
            $out[] = ['typeId' => self::PROP_WAREHOUSE, 'value' => (string) (int) $p['warehouse_id']];
        }
        if (!empty($p['shipping_profile_id'])) {
            $out[] = ['typeId' => self::PROP_SHIPPING_PROFILE, 'value' => (string) (int) $p['shipping_profile_id']];
        }
        if (!empty($p['payment']) && !empty($p['payment']['method_id'])) {
            $out[] = ['typeId' => self::PROP_PAYMENT_METHOD, 'value' => (string) (int) $p['payment']['method_id']];
        }
        if (!empty($p['customer_sign'])) {
            $out[] = ['typeId' => self::PROP_CUSTOMER_SIGN, 'value' => (string) $p['customer_sign']];
        }
        return $out;
    }

    // ==================================================================
    // Payment
    // ==================================================================

    /**
     * Legt ein Plenty-Payment an und verknüpft es mit der gerade erstellten
     * Order. Beide Aufrufe sind eigenständig, weil Plenty createPayment ohne
     * order-Bezug erlaubt und die Verknüpfung separat über
     * PaymentOrderRelationRepositoryContract läuft.
     */
    private static function createAndLinkPayment(
        AuthHelper $authHelper,
        PaymentRepositoryContract $paymentRepository,
        PaymentOrderRelationRepositoryContract $paymentOrderRelation,
        array $pay,
        int $orderId
    ): int {
        $data = [
            'mopId'             => (int) $pay['method_id'],
            'amount'            => (float) $pay['amount'],
            'currency'          => isset($pay['currency']) ? (string) $pay['currency'] : 'EUR',
            'status'            => isset($pay['status'])   ? (int) $pay['status']      : 2,  // 2 = Captured
            'transactionType'   => isset($pay['transaction_type']) ? (int) $pay['transaction_type'] : 2,
            'unaccountable'     => 0,
        ];
        if (!empty($pay['transaction_id'])) {
            $data['transactionId'] = (string) $pay['transaction_id'];
        }
        if (!empty($pay['received_at'])) {
            $data['receivedAt'] = (string) $pay['received_at'];
        }

        $payment = $authHelper->processUnguarded(function () use ($paymentRepository, $data) {
            return $paymentRepository->createPayment($data);
        });

        $paymentId = self::asInt(self::prop($payment, 'id'));
        if ($paymentId === null) {
            throw new \RuntimeException('Plenty hat ein Payment angelegt, aber keine ID zurückgegeben.');
        }

        $authHelper->processUnguarded(function () use ($paymentOrderRelation, $paymentId, $orderId) {
            return $paymentOrderRelation->createOrderRelationWithValidation($paymentId, $orderId);
        });

        return $paymentId;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private static function requireValidApiKey(Request $request, Response $response, ConfigRepository $config)
    {
        $expected = (string) $config->get('ArticleList4711.external_api_key', '');
        $provided = (string) $request->header('X-Api-Key');

        if ($expected === '' || $provided === '' || $provided !== $expected) {
            return $response->json([
                'error' => [
                    'code'    => 'unauthorized',
                    'message' => 'Missing or invalid X-Api-Key header.',
                ],
                'meta' => self::baseMeta($request),
            ], 401);
        }
        return null;
    }

    /**
     * Liest den Request-Body als JSON. Plenty's Request hat `json()` für
     * geparste Inputs; falls das fehlschlägt, fällt's auf rohen Body + decode
     * zurück (defensiver Code, weil das Plenty-Plugin-API zwischen Versionen
     * leichte Unterschiede beim Body-Handling hat).
     */
    private static function readJsonBody(Request $request)
    {
        try {
            $parsed = $request->json()->all();
            if (is_array($parsed) && !empty($parsed)) return $parsed;
        } catch (\Throwable $e) {
            // fall through
        }
        $raw = $request->getContent();
        if (!is_string($raw) || $raw === '') return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function jsonError(Response $response, Request $request, string $code, string $message, int $status)
    {
        return $response->json([
            'error' => ['code' => $code, 'message' => $message],
            'meta'  => self::baseMeta($request),
        ], $status);
    }

    private static function serializeOrderSummary($order, ?string $externalOrderId): array
    {
        return [
            'plenty_order_id'   => self::asInt(self::prop($order, 'id')),
            'external_order_id' => $externalOrderId,
        ];
    }

    private static function baseMeta(Request $request): array
    {
        return [
            'fetched_at'     => date('c'),
            'endpoint'       => '/rest/article-list-4711/external/orders',
            'schema_version' => self::SCHEMA_VERSION,
        ];
    }

    private static function asInt($v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v))     return $v;
        if (is_numeric($v)) return (int) $v;
        return null;
    }

    /**
     * Sandbox-konformer Property-Zugriff. Plenty-Models sind Objekte mit
     * öffentlichen Properties; statt method_exists/Reflection erlaubt der
     * Plenty-Plugin-Sandbox nur direkte Property-Zugriffe — daher der switch.
     */
    private static function prop($obj, string $key)
    {
        if (is_array($obj)) {
            return $obj[$key] ?? null;
        }
        if (!is_object($obj)) {
            return null;
        }
        switch ($key) {
            case 'id':    return isset($obj->id)    ? $obj->id    : null;
            case 'name':  return isset($obj->name)  ? $obj->name  : null;
        }
        return null;
    }
}
