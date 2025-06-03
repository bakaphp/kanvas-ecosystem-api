<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum CustomFieldEnum: string
{
    case ECHO_PAY_TRANSACTION_ID = 'payment_transaction_id';
    case ECHO_PAY_PAYMENT_INTENT_ID = 'payment_intent_id';
}

