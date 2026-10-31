<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\DataTransferObject;

use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Support\Money;
use Kanvas\Souk\Taxes\Enums\TaxCategoryEnum;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as WalletConfigurationEnum;
use Spatie\LaravelData\Data;

class TaxableLine extends Data
{
    public function __construct(
        public readonly string $lineId,
        public readonly string $reference,
        public readonly string $unitPrice,
        public readonly int $quantity,
        public readonly bool $isTaxable,
        public readonly ?string $taxCategory = null,
        public readonly ?string $taxCodeOverride = null,
        public readonly bool $isShipping = false,
    ) {
    }

    public static function forVariant(
        ?Variants $variant,
        bool $creditReplacesPrice,
        string $lineId,
        string $reference,
        float $unitPrice,
        int $quantity
    ): self {
        $taxCategory = $variant?->tax_category ?? $variant?->product?->tax_category;

        return new self(
            lineId: $lineId,
            reference: $reference,
            unitPrice: number_format($unitPrice, 2, '.', ''),
            quantity: $quantity,
            isTaxable: self::variantIsTaxable($variant, $creditReplacesPrice, $taxCategory),
            taxCategory: $taxCategory,
            taxCodeOverride: $variant?->tax_code_override ?? $variant?->product?->tax_code_override,
        );
    }

    public static function shipping(string $reference, float $amount): self
    {
        return new self(
            lineId: 'shipping',
            reference: $reference,
            unitPrice: number_format($amount, 2, '.', ''),
            quantity: 1,
            isTaxable: true,
            isShipping: true,
        );
    }

    public function subtotal(): string
    {
        return (string) Money::roundCollect(
            Money::of($this->unitPrice)->multipliedBy($this->quantity)
        );
    }

    private static function variantIsTaxable(?Variants $variant, bool $creditReplacesPrice, ?string $taxCategory): bool
    {
        if ($variant === null) {
            return true;
        }

        if ($variant->is_taxable === false) {
            return false;
        }

        if ($taxCategory !== null && TaxCategoryEnum::tryFrom($taxCategory) === TaxCategoryEnum::NON_TAXABLE) {
            return false;
        }

        if (! $creditReplacesPrice) {
            return true;
        }

        $variantCredit = (float) ($variant->getAttributeBySlug(WalletConfigurationEnum::VARIANT_WALLET_CREDIT_AMOUNT->value)?->value ?? 0.0);

        return $variantCredit <= 0.0;
    }
}
