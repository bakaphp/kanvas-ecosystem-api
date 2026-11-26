<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Enums;

enum CreditConfigurationEnum: string
{
    // Customer settings: the credit limit and the currency it is stated in. No limit means no limit is checked.
    case CREDIT_LIMIT_AMOUNT = 'scribe-credit-limit-amount';
    case CREDIT_LIMIT_CURRENCY = 'scribe-credit-limit-currency';

    // Company setting: days from day 0 of the payment term (the bill of lading date, or the invoice date) after which
    // an overdue invoice puts the customer's new orders on hold. The collection plan names the same day.
    case CREDIT_HOLD_DAYS = 'scribe-credit-hold-days';
}
