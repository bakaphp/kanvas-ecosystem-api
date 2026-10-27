<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Kanvas\Connectors\Stripe\Enums\StripeTaxCodeMap;
use Kanvas\Souk\Taxes\Enums\TaxCategoryEnum;
use PHPUnit\Framework\TestCase;

final class StripeTaxCodeMapTest extends TestCase
{
    public function testAllTaxCategoryEnumCasesHaveEntry(): void
    {
        foreach (TaxCategoryEnum::cases() as $case) {
            $ptc = StripeTaxCodeMap::fromTaxCategory($case);

            $this->assertNotEmpty($ptc, "TaxCategoryEnum::{$case->name} has no PTC code in StripeTaxCodeMap");
            $this->assertMatchesRegularExpression(
                '/^txcd_\d{8}$/',
                $ptc,
                "PTC code '{$ptc}' for TaxCategoryEnum::{$case->name} does not match Stripe PTC format txcd_XXXXXXXX",
            );
        }
    }

    public function testFromTaxCategoryReturnsCorrectPtcForGeneralTangible(): void
    {
        $this->assertSame('txcd_99999999', StripeTaxCodeMap::fromTaxCategory(TaxCategoryEnum::GENERAL_TANGIBLE));
    }

    public function testFromTaxCategoryReturnsCorrectPtcForNonTaxable(): void
    {
        $this->assertSame('txcd_00000000', StripeTaxCodeMap::fromTaxCategory(TaxCategoryEnum::NON_TAXABLE));
    }

    public function testFromTaxCategoryReturnsCorrectPtcForSoftware(): void
    {
        $this->assertSame('txcd_10000000', StripeTaxCodeMap::fromTaxCategory(TaxCategoryEnum::SOFTWARE));
    }

    public function testFromTaxCategoryReturnsCorrectPtcForDigitalGoods(): void
    {
        $this->assertSame('txcd_10000000', StripeTaxCodeMap::fromTaxCategory(TaxCategoryEnum::DIGITAL_GOODS));
    }
}
