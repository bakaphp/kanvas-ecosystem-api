<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Services\Concerns;

/**
 * The shape of one difference between two documents about the same order. `po_value` is the value on the document
 * being checked and `quote_value` the value on the one it was compared with; `field` says which two they are.
 */
trait BuildsDifferences
{
    /**
     * @return array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: null}|null
     */
    private function ifChanged(string $key, string $field, ?string $orderValue, ?string $otherValue): ?array
    {
        return $orderValue === $otherValue ? null : $this->difference($key, $field, $orderValue, $otherValue);
    }

    /**
     * @return array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: null}
     */
    private function difference(string $key, string $field, ?string $orderValue, ?string $otherValue): array
    {
        return [
            'key' => $key,
            'field' => $field,
            'po_value' => $orderValue,
            'quote_value' => $otherValue,
            'resolution' => null,
        ];
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
