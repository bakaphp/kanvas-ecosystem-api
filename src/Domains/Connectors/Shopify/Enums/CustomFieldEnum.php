<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Enums;

enum CustomFieldEnum: string
{
    case SHOPIFY_API_CREDENTIAL = 'SHOPIFY_CREDENTIAL';
    case SHOPIFY_API_KEY = 'SHOPIFY_API_KEY';
    case SHOPIFY_API_SECRET = 'SHOPIFY_API_SECRET';
    case SHOP_URL = 'SHOPIFY_HOSTNAME';
    case SHOPIFY_PRODUCT_ID = 'SHOPIFY_PRODUCT_ID';
    case SHOPIFY_VARIANT_ID = 'SHOPIFY_VARIANT_ID';
    case SHOPIFY_VARIANT_INVENTORY_ID = 'SHOPIFY_VARIANT_INVENTORY_ID';
    case USER_SHOPIFY_ID = 'shopify_id';
}