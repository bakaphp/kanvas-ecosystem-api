<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Enums;

enum PaymentMethodTypesEnum: string
{
    case MANUAL = 'manual';
    case CARD = 'card';
    case CASH = 'cash';
    case BANK_TRANSFER = 'bank_transfer';
    case WALLET = 'wallet';
    case PAYMENT = 'payment';
}
