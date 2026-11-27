<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Services\Concerns;

use Baka\Support\Number;
use Baka\Support\Str;

/**
 * The shape of one difference between two documents about the same order. `po_value` is the value on the document
 * being checked and `quote_value` the value on the one it was compared with; `field` says which two they are.
 */
trait BuildsDifferences
{
    /**
     * @return array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: null}|null
     */
    private function ifChanged(
        string $key,
        string $field,
        ?string $orderValue,
        ?string $otherValue
    ): ?array {
        if ($orderValue === $otherValue) {
            return null;
        }

        return $this->difference(
            $key,
            $field,
            $orderValue,
            $otherValue
        );
    }

    /**
     * @return array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: null}
     */
    private function difference(
        string $key,
        string $field,
        ?string $orderValue,
        ?string $otherValue
    ): array {
        return [
            'key' => $key,
            'field' => $field,
            'po_value' => $orderValue,
            'quote_value' => $otherValue,
            'resolution' => null,
        ];
    }

    /**
     * Two lines name the same product when their SKUs match; a document that carries no SKU is compared on its
     * description instead, so missing codes never pass a different product through unnoticed. A line missing from
     * one side is reported on its own, not here.
     *
     * @return array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: null}|null
     */
    private function productDifference(
        string $key,
        string $field,
        ?object $line,
        ?object $other
    ): ?array {
        if ($line === null || $other === null) {
            return null;
        }

        $bySku = Str::trimToNull($line->sku) !== null && Str::trimToNull($other->sku) !== null;
        $mine = trim((string) ($bySku ? $line->sku : $line->description));
        $theirs = trim((string) ($bySku ? $other->sku : $other->description));

        if (mb_strtolower($mine) === mb_strtolower($theirs)) {
            return null;
        }

        return $this->difference(
            $key,
            $field,
            $mine,
            $theirs
        );
    }

    private function number(float $value): string
    {
        return Number::withoutTrailingZeros($value, 4);
    }
}
