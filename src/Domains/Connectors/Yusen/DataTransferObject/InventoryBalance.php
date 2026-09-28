<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\DataTransferObject;

use Illuminate\Support\Carbon;

class InventoryBalance
{
    /**
     * @param array<string, InventoryBalanceLine> $lines keyed by "{item}|{warehouseCode}"
     * @param array<array-key, string> $warehouseCodes
     */
    public function __construct(
        public readonly ?string $externalId,
        public readonly ?Carbon $generatedAt,
        public readonly ?int $groupIndex,
        public readonly ?int $numGroups,
        public readonly ?int $declaredRecords,
        public readonly int $totalRecords,
        public readonly array $lines,
        public readonly array $warehouseCodes,
    ) {
    }

    public function totalQuantity(): float
    {
        $total = 0.0;

        foreach ($this->lines as $line) {
            $total += $line->quantity;
        }

        return $total;
    }

    public function multiRecordItems(): int
    {
        $count = 0;

        foreach ($this->lines as $line) {
            if ($line->recordCount > 1) {
                $count++;
            }
        }

        return $count;
    }
}
