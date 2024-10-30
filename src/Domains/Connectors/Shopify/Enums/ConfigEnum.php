<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Enums;

enum ConfigEnum: string
{
    case ACTIVITY_QUEUE = 'sync-shopify-queue';
}
