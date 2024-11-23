<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Enums;

enum ConfigEnum: string
{
    case ACTIVITY_QUEUE = 'sync-shopify-queue';
    case VARIANT_LIMIT = 'variant-limit';
    case SHOPIFY_VENDOR_DEFAULT_NAME = 'shopify-vendor-default-name';
    case SHOPIFY_PRODUCT_TYPE_AS_CATEGORY = 'shopify-product-type-as-category';
}
