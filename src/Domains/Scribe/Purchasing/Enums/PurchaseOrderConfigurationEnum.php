<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Enums;

enum PurchaseOrderConfigurationEnum: string
{
    // Company settings, in percent; none means no tolerance. Over-receipt tolerance is how much more than the open
    // quantity may be received, and also how much more than what was received a bill may charge for.
    case OVER_RECEIPT_TOLERANCE_PERCENT = 'scribe-po-over-receipt-tolerance-percent';

    // How far a bill's unit price may differ from the order's before the match flags it.
    case PRICE_TOLERANCE_PERCENT = 'scribe-po-price-tolerance-percent';
}
