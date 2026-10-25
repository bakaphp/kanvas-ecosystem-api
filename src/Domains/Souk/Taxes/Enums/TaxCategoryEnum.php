<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

enum TaxCategoryEnum: string
{
    case GENERAL_TANGIBLE = 'general_tangible';
    case DIGITAL_GOODS = 'digital_goods';
    case SOFTWARE = 'software';
    case FOOD_GROCERY = 'food_grocery';
    case CLOTHING = 'clothing';
    case SERVICES = 'services';
    case NON_TAXABLE = 'non_taxable';
}
