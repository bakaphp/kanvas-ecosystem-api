<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use Illuminate\Support\Collection;
use Kanvas\Connectors\BrushCrazy\Client;

/**
 * Reads sales from BrushCrazy in the shape SaleMapper consumes. One place for it, because the
 * bulk pull and the mirror webhook must load a sale identically or the two would write different
 * orders for the same row.
 *
 * Children are read the way BrushCrazy's relations load them — soft-deleted ones left out — so
 * the totals re-derived here match the ones BrushCrazy prints.
 */
class SaleSource
{
    /** BrushCrazy morph aliases whose rows belong to exactly one sale (`sale_id`). */
    public const array SALE_CHILD_MORPHS = [
        'sale-item' => 'sale_items',
        'sale-payment' => 'sale_payments',
        'sale-tax' => 'sale_taxes',
        'sale-discount' => 'sale_discounts',
    ];

    public const string SALE_MORPH = 'sale';

    protected ?AddonPricer $pricer = null;

    protected ?bool $hasKanvasOrderUuid = null;

    public function __construct(
        protected Client $client,
    ) {
    }

    public static function handlesMorph(string $morph): bool
    {
        return $morph === self::SALE_MORPH || isset(self::SALE_CHILD_MORPHS[$morph]);
    }

    /**
     * The sale a pointer ultimately belongs to. A mirror sale resolves to its parent, because a
     * partial refund only ever changes the parent's Order. Child rows are read with their
     * soft-deleted ones, since a deletion is itself the change being mirrored.
     */
    public function rootSaleId(string $morph, int $entityId): ?int
    {
        $saleId = $morph === self::SALE_MORPH
            ? $entityId
            : $this->client->table(self::SALE_CHILD_MORPHS[$morph])->where('id', $entityId)->value('sale_id');

        if ($saleId === null) {
            return null;
        }

        $sale = $this->client->table('sales')->where('id', (int) $saleId)->first(['id', 'original_sale']);

        if ($sale === null) {
            return null;
        }

        return (int) ($sale->original_sale ?? $sale->id);
    }

    public function find(int $saleId): ?object
    {
        return $this->client->table('sales')->where('id', $saleId)->first();
    }

    /**
     * `sales.kanvas_order_uuid` only exists once BrushCrazy runs its write-back migration. Every
     * `sales` read here selects `*`, so a sale row carries it (SaleRows::kanvasOrderUuid()) exactly
     * when the column exists; this is for a caller that names the column. Cached on the instance,
     * not statically, so a long-lived worker sees the column once the migration lands.
     */
    public function hasKanvasOrderUuid(): bool
    {
        return $this->hasKanvasOrderUuid ??= $this->client->getConnection()
            ->select("SHOW COLUMNS FROM `sales` LIKE 'kanvas_order_uuid'") !== [];
    }

    /**
     * @param  iterable<object>  $saleRows
     * @return array<int, SaleRows> keyed by sale id
     */
    public function bundles(iterable $saleRows): array
    {
        $sales = [];

        foreach ($saleRows as $row) {
            $sales[(int) $row->id] = $row;
        }

        if ($sales === []) {
            return [];
        }

        $ids = array_keys($sales);
        $items = $this->children('sale_items', $ids);
        $taxes = $this->children('sale_taxes', $ids);
        $discounts = $this->children('sale_discounts', $ids);
        $payments = $this->children('sale_payments', $ids);
        $bundles = [];

        foreach ($sales as $id => $row) {
            $bundles[$id] = new SaleRows(
                sale: $row,
                items: $items->get($id, []),
                taxes: $taxes->get($id, []),
                discounts: $discounts->get($id, []),
                payments: $payments->get($id, []),
            );
        }

        return $bundles;
    }

    /**
     * Completed partial-refund mirrors of the given sales. A mirror without `refunded_at` is a
     * refund still in flight — the controller soft-deletes it if the gateway refuses — so it is
     * not a refund yet.
     *
     * @param  array<int, int>  $parentIds
     * @return array<int, array<int, SaleRows>> parent sale id => its mirrors
     */
    public function refundsFor(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $mirrors = $this->client->table('sales')
            ->whereIn('original_sale', $parentIds)
            ->whereNotNull('refunded_at')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        $refunds = [];

        foreach ($this->bundles($mirrors) as $bundle) {
            $refunds[(int) $bundle->sale->original_sale][] = $bundle;
        }

        return $refunds;
    }

    /**
     * A sale's buyer is by definition a customer of the sale's studio (see
     * PullCustomersFromBrushCrazyAction::customersOfStudio()), so no membership filter is needed.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, object>
     */
    public function users(array $userIds): Collection
    {
        return $this->client->table('users')->whereIn('id', $userIds)->get();
    }

    /**
     * Loaded once per run: BrushCrazy prices add-ons from the whole table, archived rows included,
     * with live rows winning a name clash.
     */
    public function pricer(): AddonPricer
    {
        return $this->pricer ??= AddonPricer::fromRows(
            $this->client->table('pos_settings')
                ->orderByRaw('deleted_at IS NOT NULL')
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * @param  array<int, int>  $saleIds
     * @return Collection<int, array<int, object>>
     */
    protected function children(string $table, array $saleIds): Collection
    {
        return $this->client->table($table)
            ->whereIn('sale_id', $saleIds)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->groupBy('sale_id')
            ->map(fn (Collection $rows) => $rows->all());
    }
}
