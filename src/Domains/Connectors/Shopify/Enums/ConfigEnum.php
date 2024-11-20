<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Enums;

enum ConfigEnum: string
{
    case ACTIVITY_QUEUE = 'sync-shopify-queue';
    case VARIANT_LIMIT = 'variant-limit';
    case VENDOR_DEFAULT_NAME = 'vendor-default-name';
}
