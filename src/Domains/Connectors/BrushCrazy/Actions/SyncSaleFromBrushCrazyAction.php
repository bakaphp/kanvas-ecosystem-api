<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Enums\SaleLineKindEnum;
use Kanvas\Connectors\BrushCrazy\Jobs\RefundOrderFromBrushCrazyJob;
use Kanvas\Connectors\BrushCrazy\Mappers\SaleMapper;
use Kanvas\Connectors\BrushCrazy\Support\AddonPricer;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\RefundLedger;
use Kanvas\Connectors\BrushCrazy\Support\SaleRows;
use Kanvas\Connectors\BrushCrazy\Support\SaleSource;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use RuntimeException;
use Throwable;

/**
 * Writes one BrushCrazy sale into its studio company as a Souk Order — history, not a live order.
 * The bulk import and the mirror webhook both call it, so the two cannot drift.
 *
 * Not CreateOrderAction, on purpose: it is insert-only (a re-sync would fail on "Order number
 * already exists"), validates slot and schedule availability against events that are long past,
 * checks every product's company is active, syncs provider companies, and fires the order observer
 * (search indexing, OrderUpdateEvent). For 109k historical rows that is all cost and risk. Rows are
 * written here with model events off instead, which also means no workflow and no notification
 * ever fires. No Souk Payments rows either: CreatePaymentAction marks the order paid, which runs
 * workflows. Payments, taxes, discounts and refunds live in the order metadata.
 *
 * A sale is linked to its order by BRUSHCRAZY_SALE_ID only. `order_number` is the sale id when
 * that number is free in the company, because it is what every receipt and report already prints;
 * the site numbers its own orders in the same company, so when one of them holds it the mirrored
 * order takes Souk's next number and keeps the sale id in `metadata.brushcrazy.sale_id`. An existing
 * order is never renumbered. With no mapping (an old run that died before writing it), an order
 * numbered as the sale is reused only when its metadata names the same sale.
 *
 * A sale the site pushed (`sales.kanvas_order_uuid`) is the site's own order: it is adopted, never
 * rebuilt. Only its payment, refund and void state follow BrushCrazy, refunds go to
 * `private_metadata` because the site verifies `metadata` byte for byte, and `markAsPaid()` is never
 * called — it fires AFTER_PAYMENT_INTENT, which would push the sale back. Kanvas holds that sale's
 * money, so a refund staff book on it in BrushCrazy is recorded in the RefundLedger and returned to
 * the card by RefundOrderFromBrushCrazyJob after this write commits.
 *
 * Items are matched inside their own order by the `brushcrazy.sale_item_id` in their metadata —
 * OrderItem has no company, so a custom field on it would land under company 0 and could never be
 * scoped.
 */
class SyncSaleFromBrushCrazyAction
{
    public const string SYNCED = 'synced';
    public const string SKIPPED = 'skipped';
    public const string MISSING = 'missing';
    public const string MISROUTED = 'misrouted';

    public const string ORIGIN_KANVAS = 'kanvas';

    protected IdMapRepository $idMaps;

    protected SyncCommerceCatalogFromBrushCrazyAction $catalog;

    /** @var array<string, int|null>|null bc sale id => order id */
    protected ?array $knownOrders = null;

    /** @var array<string, Order|null> bc sale id => its existing order, once looked up */
    protected array $orders = [];

    /** @var array<string, Order|null> kanvas order uuid => the site order it names in this company */
    protected array $adoptedOrders = [];

    /** @var array<int, true> sale ids some order of the company already uses as its order_number */
    protected array $takenOrderNumbers = [];

    /** @var array<int, array<int, OrderItem>> order id => its items, soft-deleted ones included */
    protected array $itemsByOrder = [];

    /** @var array<int, true> order ids that already carry BRUSHCRAZY_ORIGIN */
    protected array $hasOrigin = [];

    /** @var array<string, int>|null bc user id => people id */
    protected ?array $knownBuyers = null;

    protected ?int $guestId = null;

    /** @var array<int, string> bc user id => why its People could not be written */
    protected array $failedBuyers = [];

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        ?SyncCommerceCatalogFromBrushCrazyAction $catalog = null,
        ?IdMapRepository $idMaps = null,
        protected ?SaleSource $source = null,
    ) {
        $this->idMaps = $idMaps ?? new IdMapRepository();
        $this->catalog = $catalog ?? new SyncCommerceCatalogFromBrushCrazyAction(
            $app,
            $user,
            $context,
            $this->idMaps
        );
    }

    /**
     * Narrows the order and buyer maps to one chunk, loads the chunk's orders and their items in
     * one pass, and creates the buyers the customer import has not reached yet, in one source query
     * instead of one per sale.
     *
     * @param  array<int, SaleRows>  $sales
     */
    public function preload(array $sales): void
    {
        $buyerIds = array_values(array_unique(array_filter(array_map(
            fn (SaleRows $sale) => isset($sale->sale->customer_id) ? (int) $sale->sale->customer_id : null,
            $sales
        ))));

        $this->knownOrders = null;
        $this->orders = [];
        $this->adoptedOrders = [];
        $this->takenOrderNumbers = [];
        $this->itemsByOrder = [];
        $this->hasOrigin = [];
        $this->loadOrders(array_values($sales));

        $this->knownBuyers = $this->idMaps->loadFor(
            $this->context->companyId(),
            People::class,
            CustomFieldEnum::BRUSHCRAZY_USER_ID,
            $buyerIds,
        ) + array_fill_keys(array_map('strval', $buyerIds), null);

        $this->importMissingBuyers(array_values(array_filter(
            $buyerIds,
            fn (int $id) => ! isset($this->knownBuyers[(string) $id])
        )));
    }

    /**
     * The webhook entry point for any sale-shaped pointer (see SaleSource::handlesMorph()):
     * re-reads the sale it belongs to and returns which result counter the pointer falls in.
     */
    public function fromPointer(string $morph, int $entityId): string
    {
        $saleId = $this->source()->rootSaleId($morph, $entityId);

        return $saleId !== null ? $this->fromSaleId($saleId) : self::MISSING;
    }

    /**
     * A mirror sale's id re-syncs its parent: a partial refund only ever changes the parent order.
     */
    public function fromSaleId(int $saleId): string
    {
        $source = $this->source();
        $row = $source->find($saleId);

        if ($row !== null && ($row->original_sale ?? null) !== null) {
            $saleId = (int) $row->original_sale;
            $row = $source->find($saleId);
        }

        if ($row === null) {
            return self::MISSING;
        }

        // Delivered to another studio's receiver; that receiver gets its own pointer.
        if ((int) ($row->studio_id ?? -1) !== $this->context->bcStudioId) {
            return self::MISROUTED;
        }

        $sale = $source->bundles([$row])[$saleId];
        $this->preload([$saleId => $sale]);

        $order = $this->fromRows($sale, $source->refundsFor([$saleId])[$saleId] ?? [], $source->pricer());

        if ($order === null) {
            return self::SKIPPED;
        }

        // Written with events off, so Scout never saw it; the bulk pull indexes per chunk instead.
        $order->searchable();

        return self::SYNCED;
    }

    /**
     * Null when the sale is not history worth an order (open ticket, deleted, or itself a refund
     * mirror) and none exists yet, or when it names a site order this company does not have. A
     * sale that became unimportable after it was imported is soft-deleted rather than left behind
     * as revenue.
     *
     * @param  array<int, SaleRows>  $refunds
     */
    public function fromRows(SaleRows $sale, array $refunds, AddonPricer $pricer): ?Order
    {
        $saleKey = (string) $sale->id();

        if (! array_key_exists($saleKey, $this->orders)) {
            $this->loadOrders([$sale]);
        }

        $existing = $this->orders[$saleKey];
        $kanvasOrderUuid = $sale->kanvasOrderUuid();

        if ($kanvasOrderUuid !== null) {
            $siteOrder = $this->adoptedOrders[$kanvasOrderUuid] ?? null;

            if ($siteOrder === null) {
                Log::warning('BrushCrazy sale names a Kanvas order this studio does not have; skipped', [
                    'sale_id' => $sale->id(),
                    'kanvas_order_uuid' => $kanvasOrderUuid,
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->context->companyId(),
                ]);

                return null;
            }

            return $this->adopt(
                $siteOrder,
                $sale,
                $refunds,
                $pricer
            );
        }

        // Mapped but not built by the mirror: the site's order, stamped by the push activity.
        if ($existing !== null && ! $this->isMirrorOf($existing, $sale->id())) {
            return $this->adopt(
                $existing,
                $sale,
                $refunds,
                $pricer
            );
        }

        $mapped = SaleMapper::map($sale, $refunds, $pricer);

        if (! $mapped['importable']) {
            if ($existing !== null && ! $existing->is_deleted) {
                Order::withoutEvents(function () use ($existing) {
                    $existing->is_deleted = true;
                    $existing->saveQuietly();
                });
                $existing->unsearchable();
            }

            return null;
        }

        $attributes = $mapped['order'] + [
            'people_id' => $this->buyerId($sale),
            'region_id' => $this->catalog->region()->getId(),
        ];

        // The mapping is written last inside the transaction: if it fails, the order rolls back
        // with it instead of being left behind unmapped.
        $order = DB::connection('commerce')->transaction(function () use ($sale, $mapped, $attributes, $existing) {
            $order = $this->upsertOrder($existing, $attributes, $sale->id());
            $this->upsertItems($order, $mapped['items'], $existing !== null);

            if (($this->knownOrders[(string) $sale->id()] ?? null) !== $order->getId()) {
                $order->disableWorkflows();
                $order->set(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value, $sale->id());
            }

            return $order;
        });

        $this->remember($sale->id(), $order);

        return $order;
    }

    /**
     * The site's order is the sale: its items, buyer, totals and number stay as the site wrote
     * them. A mirror order an earlier run built for the same sale — the webhook can read the sale
     * before BrushCrazy stamps its uuid — is soft-deleted so the sale is counted once.
     *
     * @param  array<int, SaleRows>  $refunds
     */
    protected function adopt(
        Order $order,
        SaleRows $sale,
        array $refunds,
        AddonPricer $pricer
    ): ?Order {
        $saleKey = (string) $sale->id();
        $brushcrazy = SaleMapper::map($sale, $refunds, $pricer)['order']['metadata']['brushcrazy'];
        $refundMoves = SaleMapper::kanvasRefundMoves($sale, $refunds);
        $voidedAt = $sale->sale->deleted_at ?? null;

        $duplicate = $this->orders[$saleKey] ?? null;
        $duplicate = $duplicate !== null && $duplicate->getId() !== $order->getId() && $this->isMirrorOf($duplicate, $sale->id())
            ? $duplicate
            : null;

        $ledger = DB::connection('commerce')->transaction(function () use ($order, $sale, $saleKey, $brushcrazy, $refundMoves, $voidedAt, $duplicate) {
            // The refund job writes this row's ledger too; read it under the lock, not from preload.
            $locked = Order::withTrashed()->whereKey($order->getId())->lockForUpdate()->first();

            if ($locked !== null) {
                $order->setRawAttributes($locked->getAttributes(), true);
            }

            $privateMetadata = is_array($order->private_metadata) ? $order->private_metadata : [];
            $previous = is_array($privateMetadata['brushcrazy'] ?? null) ? $privateMetadata['brushcrazy'] : [];
            $ledger = RefundLedger::reconcile(RefundLedger::of($order), $refundMoves, now()->toIso8601String());
            $cardRefunded = RefundLedger::hasRefundedFullRefund($ledger);
            $privateMetadata['brushcrazy'] = [
                'sale_id' => $sale->id(),
                'voided_at' => $voidedAt,
                'refunded_at' => $brushcrazy['refunded_at'],
                'refund_status' => $brushcrazy['refund_status'],
                'totals' => array_intersect_key($brushcrazy['totals'], array_flip([
                    'amount_refunded_cents',
                    'item_refunded_cents',
                    'credit_refunded_cents',
                    'full_refund_cents',
                    'full_refund_via',
                    'refunded_cents',
                ])),
                'refund_lines' => $brushcrazy['refunds'],
                'refunds' => $ledger,
            ];

            Order::withoutEvents(function () use ($order, $brushcrazy, $voidedAt, $previous, $privateMetadata, $duplicate, $cardRefunded) {
                $order->private_metadata = $privateMetadata;

                if ($voidedAt !== null) {
                    $order->is_deleted = true;
                } elseif ($order->is_deleted && ($previous['voided_at'] ?? null) !== null) {
                    $order->is_deleted = false;
                }

                if ($brushcrazy['refund_status'] === 'full') {
                    if ($order->payment_status !== PaymentStatusEnum::REVERSED->value) {
                        $order->payment_status = PaymentStatusEnum::REVERSED->value;
                    }
                } elseif ($order->payment_status === PaymentStatusEnum::REVERSED->value && ($previous['refund_status'] ?? null) === 'full' && ! $cardRefunded) {
                    // Only a reversal this sync made is undone when BrushCrazy reverts its refund,
                    // and never once Kanvas returned the money to the card.
                    $order->payment_status = PaymentStatusEnum::PAID->value;
                    $order->paid_at = $brushcrazy['completed_at'] ?? $order->paid_at;
                }

                if ($order->isDirty()) {
                    $order->saveQuietly();
                }

                if ($duplicate !== null && ! $duplicate->is_deleted) {
                    $duplicate->is_deleted = true;
                    $duplicate->saveQuietly();
                }
            });

            if ($duplicate !== null) {
                $duplicate->disableWorkflows();
                $duplicate->del(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value);
            }

            $order->disableWorkflows();

            if (($this->knownOrders[$saleKey] ?? null) !== $order->getId()) {
                $order->set(CustomFieldEnum::BRUSHCRAZY_SALE_ID->value, $sale->id());
            }

            if (! isset($this->hasOrigin[$order->getId()])) {
                $order->set(CustomFieldEnum::BRUSHCRAZY_ORIGIN->value, self::ORIGIN_KANVAS);
                $this->hasOrigin[$order->getId()] = true;
            }

            return $ledger;
        });

        if ($duplicate !== null) {
            $duplicate->unsearchable();
        }

        // After the commit and outside every mirror lock: the money moves in its own retryable job.
        // A pending entry whose job was lost is dispatched again by the next sync.
        foreach (RefundLedger::pendingKeys($ledger) as $refundKey) {
            RefundOrderFromBrushCrazyJob::dispatch($order->app, $order, $refundKey);
        }

        $this->remember($sale->id(), $order);

        if ($order->is_deleted) {
            $order->unsearchable();

            return null;
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function upsertOrder(?Order $existing, array $attributes, int $saleId): Order
    {
        $createdAt = $attributes['created_at'] ?? null;
        $preferredNumber = (int) ($attributes['order_number'] ?? $saleId);
        unset($attributes['created_at'], $attributes['order_number']);

        return Order::withoutEvents(function () use ($existing, $attributes, $createdAt, $preferredNumber) {
            if ($existing !== null) {
                $existing->fill($attributes);

                if ($existing->isDirty()) {
                    $existing->saveQuietly();
                }

                // Restored because its sale came back (moved back to this studio, un-voided): the
                // deleted-by-mirror marker would otherwise let a later Kanvas-side delete be undone.
                if ($existing->wasChanged('is_deleted') && ! $existing->is_deleted) {
                    $existing->disableWorkflows();
                    $existing->del(CustomFieldEnum::BRUSHCRAZY_DELETED_BY_MIRROR->value);
                }

                return $existing;
            }

            $order = new Order();
            $order->fill($attributes);
            $order->uuid = Str::uuid()->toString();
            $order->apps_id = $this->app->getId();
            $order->companies_id = $this->context->companyId();
            $order->users_id = $this->user->getId();
            // A site order already holds the sale id: Souk's next number instead (it locks the
            // company's highest order inside this transaction).
            $order->order_number = isset($this->takenOrderNumbers[$preferredNumber])
                ? $order->generateOrderNumber()
                : $preferredNumber;
            $this->takenOrderNumbers[(int) $order->order_number] = true;

            if ($createdAt !== null) {
                $order->created_at = $createdAt;
            }

            $order->saveQuietly();

            return $order;
        });
    }

    /**
     * A line that disappeared from the source (deleted off the ticket) is soft-deleted here too, so
     * the order keeps matching BrushCrazy's receipt.
     *
     * @param  array<int, array{sale_item_id: int, product_id: int|null, kind: SaleLineKindEnum, attributes: array<string, mixed>}>  $items
     */
    protected function upsertItems(Order $order, array $items, bool $orderExisted): void
    {
        $existingItems = [];

        if ($orderExisted) {
            $loaded = $this->itemsByOrder[$order->getId()]
                ?? OrderItem::withTrashed()->where('order_id', $order->getId())->get()->all();

            foreach ($loaded as $existing) {
                $saleItemId = $existing->metadata['brushcrazy']['sale_item_id'] ?? null;

                if ($saleItemId !== null) {
                    $existingItems[(int) $saleItemId] = $existing;
                }
            }
        }

        // New rows are not in the preloaded list; a second pass over this order reads them fresh.
        unset($this->itemsByOrder[$order->getId()]);

        OrderItem::withoutEvents(function () use ($order, $items, &$existingItems) {
            foreach ($items as $item) {
                $variant = $this->catalog->variantFor($item['product_id'], $item['kind']);
                $attributes = $item['attributes'] + [
                    'variant_id' => $variant->getId(),
                    'variant_name' => (string) $variant->name,
                    'product_sku' => (string) $variant->sku,
                ];
                $createdAt = $attributes['created_at'] ?? null;
                unset($attributes['created_at']);

                $orderItem = $existingItems[$item['sale_item_id']] ?? null;
                unset($existingItems[$item['sale_item_id']]);

                if ($orderItem !== null) {
                    $orderItem->fill($attributes);

                    if ($orderItem->isDirty()) {
                        $orderItem->saveQuietly();
                    }

                    continue;
                }

                $orderItem = new OrderItem();
                $orderItem->fill($attributes);
                $orderItem->uuid = Str::uuid()->toString();
                $orderItem->apps_id = $this->app->getId();
                $orderItem->order_id = $order->getId();

                if ($createdAt !== null) {
                    $orderItem->created_at = $createdAt;
                }

                $orderItem->saveQuietly();
            }

            foreach ($existingItems as $gone) {
                if (! $gone->is_deleted) {
                    $gone->is_deleted = true;
                    $gone->saveQuietly();
                }
            }
        });
    }

    /**
     * A sale with no customer, or whose user is gone from the source, belongs to the studio's
     * unknown guest — Souk requires a buyer on every order.
     */
    protected function buyerId(SaleRows $sale): int
    {
        $bcUserId = isset($sale->sale->customer_id) ? (int) $sale->sale->customer_id : null;

        if ($bcUserId !== null) {
            if ($this->knownBuyers === null || ! array_key_exists((string) $bcUserId, $this->knownBuyers)) {
                $this->knownBuyers = ($this->knownBuyers ?? []) + $this->idMaps->loadFor(
                    $this->context->companyId(),
                    People::class,
                    CustomFieldEnum::BRUSHCRAZY_USER_ID,
                    [$bcUserId],
                );
            }

            if (isset($this->knownBuyers[(string) $bcUserId])) {
                return $this->knownBuyers[(string) $bcUserId];
            }

            // The buyer exists but could not be written: fail the sale rather than hand a known
            // customer's purchase to the guest, which no later run would ever correct.
            if (isset($this->failedBuyers[$bcUserId])) {
                throw new RuntimeException("Buyer user:{$bcUserId} could not be imported — {$this->failedBuyers[$bcUserId]}");
            }
        }

        return $this->guestId ??= (int) new ResolveBrushCrazyUnknownGuestAction(
            $this->app,
            $this->user,
            $this->context,
            $this->idMaps
        )->execute()->getId();
    }

    /**
     * The customer import normally ran first; this covers a buyer it has not reached — a sale newer
     * than its last pass, or a run filtered to sales only. Through SyncCustomerFromBrushCrazyAction,
     * the one write path for People.
     *
     * @param  array<int, int>  $bcUserIds
     */
    protected function importMissingBuyers(array $bcUserIds): void
    {
        if ($bcUserIds === []) {
            return;
        }

        $customers = new SyncCustomerFromBrushCrazyAction(
            $this->app,
            $this->user,
            $this->context,
            $this->idMaps
        );
        $customers->preload($bcUserIds);

        foreach ($this->source()->users($bcUserIds) as $row) {
            try {
                $this->knownBuyers[(string) $row->id] = (int) $customers->fromRow($row)->getId();
            } catch (Throwable $e) {
                $this->failedBuyers[(int) $row->id] = $e->getMessage();
            }
        }
    }

    /**
     * One query for every order the sales can resolve to — by mapping, by the site uuid, or by the
     * order_number an unmapped earlier run gave them — and one for those orders' items. Also
     * records which sale ids are already taken as order numbers.
     *
     * @param  array<int, SaleRows>  $sales
     */
    protected function loadOrders(array $sales): void
    {
        $saleIds = array_map(fn (SaleRows $sale) => $sale->id(), $sales);
        $missingFromMap = $this->knownOrders === null
            ? $saleIds
            : array_values(array_filter($saleIds, fn (int $id) => ! array_key_exists((string) $id, $this->knownOrders)));

        // Misses are kept as null so a new sale is not looked up a second time on its own.
        $this->knownOrders = ($this->knownOrders ?? []) + $this->idMaps->loadFor(
            $this->context->companyId(),
            Order::class,
            CustomFieldEnum::BRUSHCRAZY_SALE_ID,
            $missingFromMap,
        ) + array_fill_keys(array_map('strval', $missingFromMap), null);

        $uuids = array_values(array_unique(array_filter(array_map(fn (SaleRows $sale) => $sale->kanvasOrderUuid(), $sales))));
        $mappedIds = array_values(array_filter(array_map(fn (int $id) => $this->knownOrders[(string) $id] ?? null, $saleIds)));

        $orders = $saleIds === [] ? collect() : Order::withTrashed()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->context->companyId())
            ->where(function ($query) use ($saleIds, $mappedIds, $uuids) {
                $query->whereIn('order_number', $saleIds);

                if ($mappedIds !== []) {
                    $query->orWhereIn('id', $mappedIds);
                }

                if ($uuids !== []) {
                    $query->orWhereIn('uuid', $uuids);
                }
            })
            ->get();

        $byId = $orders->keyBy('id');
        $byNumber = $orders->keyBy('order_number');
        $byUuid = $orders->keyBy('uuid');
        $siteOrderIds = [];
        $mirrorOrderIds = [];

        foreach ($sales as $sale) {
            $saleId = $sale->id();
            $numbered = $byNumber->get($saleId);
            $mappedId = $this->knownOrders[(string) $saleId] ?? null;
            $uuid = $sale->kanvasOrderUuid();

            if ($numbered !== null) {
                $this->takenOrderNumbers[$saleId] = true;
            }

            $existing = ($mappedId !== null ? $byId->get($mappedId) : null)
                ?? ($numbered !== null && $this->isMirrorOf($numbered, $saleId) ? $numbered : null);
            $this->orders[(string) $saleId] = $existing;

            if ($uuid !== null) {
                $this->adoptedOrders[$uuid] = $byUuid->get($uuid);

                if ($this->adoptedOrders[$uuid] !== null) {
                    $siteOrderIds[] = $this->adoptedOrders[$uuid]->getId();
                }
            }

            if ($existing === null) {
                continue;
            }

            if ($this->isMirrorOf($existing, $saleId)) {
                $mirrorOrderIds[] = $existing->getId();
            } else {
                $siteOrderIds[] = $existing->getId();
            }
        }

        if ($mirrorOrderIds !== []) {
            $items = OrderItem::withTrashed()->whereIn('order_id', $mirrorOrderIds)->get()->groupBy('order_id');

            foreach ($mirrorOrderIds as $orderId) {
                $this->itemsByOrder[$orderId] = $items->get($orderId)?->all() ?? [];
            }
        }

        $origins = $this->idMaps->loadReverse(
            $this->context->companyId(),
            Order::class,
            CustomFieldEnum::BRUSHCRAZY_ORIGIN,
            array_values(array_unique($siteOrderIds)),
        );

        foreach (array_keys($origins) as $orderId) {
            $this->hasOrigin[$orderId] = true;
        }
    }

    /** Only the mirror writes `metadata.brushcrazy.sale_id`; the site's orders never carry it. */
    protected function isMirrorOf(Order $order, int $saleId): bool
    {
        return (int) ($order->metadata['brushcrazy']['sale_id'] ?? 0) === $saleId;
    }

    protected function remember(int $saleId, Order $order): void
    {
        $this->knownOrders[(string) $saleId] = $order->getId();
        $this->orders[(string) $saleId] = $order;
    }

    protected function source(): SaleSource
    {
        return $this->source ??= new SaleSource(new Client($this->app));
    }
}
