<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Enums;

use Kanvas\Souk\Taxes\Enums\TaxCategoryEnum;

final class StripeTaxCodeMap
{
    public const GENERAL_TANGIBLE = 'txcd_99999999';
    public const DIGITAL_GOODS = 'txcd_10000000';
    public const SOFTWARE = 'txcd_10000000';
    public const FOOD_GROCERY = 'txcd_40060003';
    public const CLOTHING = 'txcd_20010001';
    public const SERVICES = 'txcd_90000001';
    public const NON_TAXABLE = 'txcd_00000000';

    private function __construct()
    {
    }

    public static function fromTaxCategory(TaxCategoryEnum $category): string
    {
        return match ($category) {
            TaxCategoryEnum::GENERAL_TANGIBLE => self::GENERAL_TANGIBLE,
            TaxCategoryEnum::DIGITAL_GOODS => self::DIGITAL_GOODS,
            TaxCategoryEnum::SOFTWARE => self::SOFTWARE,
            TaxCategoryEnum::FOOD_GROCERY => self::FOOD_GROCERY,
            TaxCategoryEnum::CLOTHING => self::CLOTHING,
            TaxCategoryEnum::SERVICES => self::SERVICES,
            TaxCategoryEnum::NON_TAXABLE => self::NON_TAXABLE,
        };
    }
}
