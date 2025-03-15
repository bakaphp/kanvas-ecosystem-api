<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

enum GooglePlayReceiptStatusEnum: int
{
    case PURCHASED = 0;
    case CANCELED = 1;
    case PENDING = 2;
}
