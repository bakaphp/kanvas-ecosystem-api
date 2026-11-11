<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Mappers;

use Baka\Support\Str;
use Kanvas\Connectors\BrushCrazy\Enums\SaleLineKindEnum;
use Kanvas\Connectors\BrushCrazy\Support\Money;

/**
 * One BrushCrazy `products` row (global at the source) as an Inventory Product + Variant inside one
 * studio company. Free of lookups so it stays unit-testable.
 *
 * The price is the studio's `product_settings` override when it has one, otherwise the global MSRP —
 * the same precedence the POS uses when it rings the product up.
 */
class ProductMapper
{
    /** `products_variants.sku` / `barcode` are 64 characters wide; BrushCrazy barcodes are 191. */
    private const int VARIANT_CODE_MAX = 64;

    /**
     * @return array{product: array<string, mixed>, variant: array<string, mixed>, price: float}
     */
    public static function map(object $product, ?object $studioSetting = null): array
    {
        $name = Str::trimToNull($product->name ?? null) ?? 'Product ' . (int) $product->id;
        $barcode = Str::trimToNull($product->barcode ?? null);
        $fitsVariant = $barcode !== null && mb_strlen($barcode) <= self::VARIANT_CODE_MAX;
        $isDeleted = ($product->deleted_at ?? null) !== null;

        return [
            'product' => [
                'name' => $name,
                'slug' => self::slug((int) $product->id),
                'description' => (string) ($product->description ?? ''),
                'upc' => $barcode,
                'is_published' => ! $isDeleted
                    && ($product->published_at ?? null) !== null
                    && ($product->discontinued_at ?? null) === null,
                'published_at' => $product->published_at ?? null,
                'is_deleted' => $isDeleted,
            ],
            'variant' => [
                'name' => $name,
                'slug' => self::slug((int) $product->id),
                'sku' => $fitsVariant ? $barcode : self::slug((int) $product->id),
                'barcode' => $fitsVariant ? $barcode : null,
                'description' => Str::trimToNull($product->description ?? null),
                'is_published' => true,
                'is_deleted' => $isDeleted,
            ],
            'price' => Money::toDollars(self::priceCents($product, $studioSetting)),
        ];
    }

    /**
     * Generic catalog line for sale items with no BrushCrazy product behind them.
     *
     * @return array{product: array<string, mixed>, variant: array<string, mixed>}
     */
    public static function genericLine(SaleLineKindEnum $kind): array
    {
        $slug = 'bc-sale-line-' . $kind->value;

        return [
            'product' => [
                'name' => $kind->label(),
                'slug' => $slug,
                'description' => 'BrushCrazy sale lines with no catalog product: ' . $kind->label(),
                'is_published' => false,
                'is_deleted' => false,
            ],
            'variant' => [
                'name' => $kind->label(),
                'slug' => $slug,
                'sku' => $slug,
                'is_published' => true,
                'is_deleted' => false,
            ],
        ];
    }

    public static function priceCents(object $product, ?object $studioSetting = null): int
    {
        if ($studioSetting !== null && ($studioSetting->price ?? null) !== null) {
            return Money::fromRaw($studioSetting->price)->cents;
        }

        return Money::fromRaw($product->msrp ?? null)->cents;
    }

    protected static function slug(int $bcProductId): string
    {
        return 'bc-product-' . $bcProductId;
    }
}
