<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

enum ConfigurationEnum: string
{
    case APPLE_PAYMENT_SHARED_SECRET = 'APPLE_PAYMENT_SHARED_SECRET';
    case GOOGLE_PLAY_PACKAGE_NAME = 'GOOGLE_PLAY_PACKAGE_NAME';
}
