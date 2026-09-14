<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Sources;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Connectors\Yusen\Contracts\InventoryQuantitySource;
use Kanvas\Connectors\Yusen\Services\YusenSettings;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Override;

/**
 * What Kanvas believes it holds, read from the warehouse the ERP feed writes to.
 *
 * Also exposes the variant behind each item so the report can carry `variant_id` — the only
 * source that can, which is why resolution lives here rather than in the comparator.
 */
class KanvasWarehouseQuantitySource implements InventoryQuantitySource
{
    private const int CHUNK_SIZE = 500;

    /** @var array<string, array{id: int, name: string|null}> */
    private array $variants = [];

    private bool $variantsLoaded = false;

    private ?int $warehouseId = null;

    private bool $warehouseResolved = false;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly YusenSettings $settings,
    ) {
    }

    #[Override]
    public function key(): string
    {
        return 'kanvas';
    }

    #[Override]
    public function quantities(): array
    {
        $warehouseId = $this->warehouseId();

        if ($warehouseId === null) {
            return [];
        }

        $this->loadVariants();

        $balances = VariantsWarehouses::query()
            ->where('warehouses_id', $warehouseId)
            ->pluck('quantity', 'products_variants_id');

        $quantities = [];

        foreach ($this->variants as $item => $variant) {
            $quantities[$item] = (float) ($balances[$variant['id']] ?? 0);
        }

        return $quantities;
    }

    #[Override]
    public function describe(string $item): ?string
    {
        return $this->variant($item)['name'] ?? null;
    }

    public function variantId(string $item): ?int
    {
        return $this->variant($item)['id'] ?? null;
    }

    /**
     * Whether a warehouse could be resolved at all. Without one there is nothing to compare, and
     * the report says so rather than reporting every item as a zero-quantity mismatch.
     */
    public function isConfigured(): bool
    {
        return $this->warehouseId() !== null;
    }

    /**
     * @return array{id: int, name: string|null}|null
     */
    private function variant(string $item): ?array
    {
        $this->loadVariants();

        return $this->variants[$item] ?? null;
    }

    private function warehouseId(): ?int
    {
        if ($this->warehouseResolved) {
            return $this->warehouseId;
        }

        $this->warehouseResolved = true;
        $configured = $this->settings->primaryWarehouseId();

        if ($configured !== null) {
            return $this->warehouseId = $configured;
        }

        $default = Warehouses::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('is_default', 1)
            ->first();

        return $this->warehouseId = $default !== null ? (int) $default->getId() : null;
    }

    /**
     * Every variant carrying the match field, keyed by it. Loaded whole rather than filtered to
     * the file's items so the comparator can see stock Yusen never mentioned — which is why only
     * the three columns the report needs are selected, and why they are kept as plain arrays: a
     * six-figure catalog of hydrated models does not need to sit in the worker.
     */
    private function loadVariants(): void
    {
        if ($this->variantsLoaded) {
            return;
        }

        $this->variantsLoaded = true;
        $matchField = $this->settings->matchField();

        Variants::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereNotNull($matchField)
            ->where($matchField, '!=', '')
            ->select(['id', 'name', $matchField])
            ->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use ($matchField): void {
                /** @var Variants $variant */
                foreach ($chunk as $variant) {
                    // Two variants sharing a barcode makes the mapping ambiguous; first wins, and
                    // the duplicate surfaces as its own data-quality problem rather than
                    // flip-flopping which variant the report blames each night.
                    $this->variants[(string) $variant->{$matchField}] ??= [
                        'id' => (int) $variant->getId(),
                        'name' => $variant->name !== null ? (string) $variant->name : null,
                    ];
                }
            });
    }
}
