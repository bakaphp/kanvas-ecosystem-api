<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum CustomFieldEnum: string
{
    case ECHO_PAY_TRANSACTION_ID = 'payment_transaction_id';
    case ECHO_PAY_PAYMENT_INTENT_ID = 'payment_intent_id';
    case ECHO_PAY_PAYMENT_STATUS = 'payment_status';
    case ECHO_PAY_PAYMENT_RESPONSE = 'payment_response';
    case ECHO_PAY_SHOULD_CAPTURE = 'should_capture';
    case ECHO_PAY_AUTH_TRANSACTION_ID = 'auth_transaction_id';

    case ECHO_PAY_MERCHANT_KEY = 'merchant_key';
    case ECHO_PAY_CHANNEL_CODE = 'channel_code';
    case ECHO_PAY_SERVICE_CODE = 'service_code';
    case ECHO_PAY_SERVICE_TYPE_ID = 'service_type_id';
    case ECHO_PAY_CONTRACT = 'contract';
}
