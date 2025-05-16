<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum MerchantPlatformEnum: string
{
    case WEB = "WEB";
    case MOBILE = "MOBILE";
}
