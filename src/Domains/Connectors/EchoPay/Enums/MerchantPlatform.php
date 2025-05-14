<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum MerchantPlatformEnum: string
{
    case PLATFORM_WEB = "WEB";
    case PLATFORM_MOBILE = "MOBILE";
}
