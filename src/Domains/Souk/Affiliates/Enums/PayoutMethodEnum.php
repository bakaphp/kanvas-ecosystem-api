<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Enums;

enum PayoutMethodEnum: string
{
    case WALLET = 'wallet';
    case BANK_TRANSFER = 'bank_transfer';
    case PAYPAL = 'paypal';
    case STRIPE = 'stripe';
}
