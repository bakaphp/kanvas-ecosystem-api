<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Enums;

enum ConfigurationEnum: string
{
    // Company-level user id of the person who pays. Unset means the company owner.
    case PAYER_USER_ID = 'scribe-payment-run-payer-user-id';
}
