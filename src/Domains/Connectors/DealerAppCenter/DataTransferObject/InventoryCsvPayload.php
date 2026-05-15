<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerAppCenter\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * Normalized representation of a single rooftop's inventory CSV.
 *
 * Mirrors the response envelope's `data` section so the merger can stack
 * multiple payloads with minimal massaging. All cell values are strings,
 * empty cells are "" (never null), no BOM, no header row inside fileValues.
 */
class InventoryCsvPayload extends Data
{
    /**
     * @param list<string>        $fileHeaders
     * @param list<string>        $fileFirstRow
     * @param list<list<string>>  $fileValues
     */
    public function __construct(
        public readonly string $rooftopId,
        public readonly array $fileHeaders,
        public readonly array $fileFirstRow,
        public readonly array $fileValues,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->fileValues === [];
    }
}
