<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Taxes\DataTransferObject\TaxableLine;
use Kanvas\Souk\Taxes\Enums\TaxCategoryEnum;
use Tests\TestCase;

final class TaxableLineTest extends TestCase
{
    private function lineFor(Variants $variant): TaxableLine
    {
        return TaxableLine::forVariant(
            variant: $variant,
            creditReplacesPrice: false,
            lineId: 'line-1',
            reference: 'ref-1',
            unitPrice: 100.0,
            quantity: 1,
        );
    }

    public function testVariantDefaultsToTaxable(): void
    {
        $line = $this->lineFor(new Variants(['is_taxable' => true]));

        $this->assertTrue($line->isTaxable);
        $this->assertSame('100.00', $line->unitPrice);
    }

    public function testNonTaxableCategoryIsExempt(): void
    {
        $line = $this->lineFor(new Variants([
            'is_taxable' => true,
            'tax_category' => TaxCategoryEnum::NON_TAXABLE->value,
        ]));

        $this->assertFalse($line->isTaxable);
        $this->assertSame(TaxCategoryEnum::NON_TAXABLE->value, $line->taxCategory);
    }

    public function testIsTaxableFalseColumnIsExempt(): void
    {
        $line = $this->lineFor(new Variants(['is_taxable' => false]));

        $this->assertFalse($line->isTaxable);
    }

    public function testVariantTaxFieldsArePassedThrough(): void
    {
        $line = $this->lineFor(new Variants([
            'is_taxable' => true,
            'tax_category' => 'tobacco',
            'tax_code_override' => 'txcd_99999999',
        ]));

        $this->assertTrue($line->isTaxable);
        $this->assertSame('tobacco', $line->taxCategory);
        $this->assertSame('txcd_99999999', $line->taxCodeOverride);
    }

    public function testShippingLine(): void
    {
        $line = TaxableLine::shipping('shipping-abc', 10.5);

        $this->assertTrue($line->isShipping);
        $this->assertTrue($line->isTaxable);
        $this->assertSame('10.50', $line->unitPrice);
        $this->assertSame(1, $line->quantity);
    }
}
