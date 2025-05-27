<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'ECHO_PAY_BASE_URL';
    case APP_TOKEN = 'ECHO_PAY_APP_TOKEN';
    case CLIENT_ID = 'ECHO_PAY_CLIENT_ID';
    case SECRET = 'ECHO_PAY_SECRET';
    case SANDBOX_URL = 'https://api-test.portall.com.do:2053';

    case AUTHORIZATION_PATH = '/api/v2/auth/token';
    case CONSULT_SERVICE_PATH = '/api/v2/echo-pay/service';
    case ADD_CARD_PATH = '/api/v2/echo-pay/tms/card';
    case SETUP_PAYER_PATH = '/api/v2/echo-pay/3ds/setup-payer';
    case CHECK_PAYER_ENROLLMENT_PATH = '/api/v2/echo-pay/3ds/check-payer-enrollment';
    case VALIDATE_PAYER_AUTH_RESULT_PATH = '/api/v2/echo-pay/3ds/validate-auth-result';
    case PAY_SERVICE_PATH = '/api/v2/echo-pay/service/pay';
}
