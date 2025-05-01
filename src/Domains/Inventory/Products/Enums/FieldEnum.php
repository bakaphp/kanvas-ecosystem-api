<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Enums;

enum FieldEnum: string
{
    case SKU = 'sku';
    case PRODUCT_NAME = 'product_name';
    case VARIANT_NAME = 'variat_name';
    case DESCRIPTION = 'description';
    case SLUG = 'slug';
    case SHORT_DESCRIPTION = 'short_description';
    case HTML_DESCRIPTION = 'html_description';
    case WARRANTY_TERMS = 'warranty_terms';
    case UPC = 'upc';
    case IS_PUBLISHED = 'is_published';
    case STATUS = 'status';
    case FILES = 'files';
    case PRICE = 'price';
    case WEIGHT = 'weight';
    case BARCODE = 'weight';
    case SERIAL_NUMBER = 'weight';
    case SERIAL_NUMBER = 'weight';

    public static function labels(): array
    {
        return [
            self::SKU->value => ['required' => true],
            self::PRODUCT_NAME->value => ['required' => true],
            self::VARIANT_NAME->value => ['required' => false],
            self::DESCRIPTION->value => ['required' => false],
            self::SLUG->value => ['required' => false],
            self::SHORT_DESCRIPTION->value => ['required' => false],
            self::HTML_DESCRIPTION->value => ['required' => false],
            self::WARRANTY_TERMS->value => ['required' => false],
            self::UPC->value => ['required' => false],
            self::IS_PUBLISHED->value => ['required' => false],
            self::STATUS->value => ['required' => false],
            self::FILES->value => ['required' => false],
            self::PRICE->value => ['required' => false],
            self::WEIGHT->value => ['required' => false],
        ];
    }
}
