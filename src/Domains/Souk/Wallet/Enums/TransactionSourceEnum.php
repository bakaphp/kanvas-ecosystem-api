<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Enums;

enum TransactionSourceEnum: string
{
    case RECHARGE_MANUAL = 'recharge_manual';
    case RECHARGE_AUTO = 'recharge_auto';
    case PAYMENT = 'payment';
    case REFUND_TECHNICAL = 'refund_technical';
    case REFUND_PRE_SERVICE = 'refund_pre_service';
    case FLUSH = 'flush';
}
