<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Enums;

enum CustomFieldEnum: string
{
    case SHOPIFY_API_CREDENTIAL = 'SHOPIFY_CREDENTIAL_';
    case SHOPIFY_API_KEY = 'SHOPIFY_API_KEY';
    case SHOPIFY_PRODUCT_ID = 'SHOPIFY_PRODUCT_ID';
    case SHOPIFY_API_SECRET = 'SHOPIFY_API_SECRET';
    case SHOP_URL = 'SHOPIFY_HOSTNAME';
}
