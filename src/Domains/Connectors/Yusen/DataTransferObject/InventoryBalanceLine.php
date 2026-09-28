<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\DataTransferObject;

/**
 * One item in one warehouse, already aggregated across every `<Inventory>` record Yusen sent
 * for it. Plain object rather than Spatie Data: a 100k-line file allocates one of these per
 * item and the Data pipeline's per-instance cost is not worth paying here.
 */
class InventoryBalanceLine
{
    public function __construct(
        public readonly string $item,
        public readonly string $warehouseCode,
        public float $quantity = 0.0,
        public float $allocatedQuantity = 0.0,
        public float $inTransitQuantity = 0.0,
        public float $suspenseQuantity = 0.0,
        public int $recordCount = 0,
        public ?string $description = null,
        public ?string $style = null,
        public ?string $color = null,
        public ?string $size = null,
        /** @var array<string, float> */
        public array $statusBreakdown = [],
    ) {
    }

    public function addRecord(
        float $quantity,
        float $allocated,
        float $inTransit,
        float $suspense,
        ?string $status,
    ): void {
        $this->quantity += $quantity;
        $this->allocatedQuantity += $allocated;
        $this->inTransitQuantity += $inTransit;
        $this->suspenseQuantity += $suspense;
        $this->recordCount++;

        $key = $status !== null && $status !== '' ? $status : 'Unknown';
        $this->statusBreakdown[$key] = ($this->statusBreakdown[$key] ?? 0.0) + $quantity;
    }

    public function describeFrom(
        ?string $description,
        ?string $style,
        ?string $color,
        ?string $size,
    ): void {
        $this->description ??= $description;
        $this->style ??= $style;
        $this->color ??= $color;
        $this->size ??= $size;
    }
}
