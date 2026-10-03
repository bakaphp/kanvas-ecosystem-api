<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Enums;

enum ConfigurationEnum: string
{
    // Stored in app settings
    case AZUL_AUTH1 = 'AZUL_AUTH1';
    case AZUL_AUTH2 = 'AZUL_AUTH2';
    case AZUL_STORE = 'AZUL_STORE';
    case AZUL_CHANNEL = 'AZUL_CHANNEL';
    case AZUL_BASE_URL = 'AZUL_BASE_URL';
    case AZUL_FAILOVER_URL = 'AZUL_FAILOVER_URL';
    case AZUL_CERT = 'AZUL_CERT';
    case AZUL_KEY = 'AZUL_KEY';
    case AZUL_CA = 'AZUL_CA';
    case AZUL_KEY_PASSWORD = 'AZUL_KEY_PASSWORD';

    case AZUL_VERIFY_SSL = 'AZUL_VERIFY_SSL';
    case AZUL_USE_HOLD = 'AZUL_USE_HOLD';     // Use Hold+Post (two-step) instead of immediate Sale
    case AZUL_DEBUG_LOG = 'AZUL_DEBUG_LOG';
    case AZUL_3DS_TERM_URL = 'AZUL_3DS_TERM_URL';         // TermUrl for 3DS redirect after challenge
    case AZUL_3DS_METHOD_NOTIFICATION_URL = 'AZUL_3DS_METHOD_NOTIFICATION_URL'; // MethodNotificationUrl for 3DS method data

    // Legacy file-path fallbacks, read by AzulCertificate only when AZUL_CERT/AZUL_KEY/AZUL_CA are unset
    case AZUL_CERT_PATH = 'AZUL_CERT_PATH';
    case AZUL_KEY_PATH = 'AZUL_KEY_PATH';
    case AZUL_CA_PATH = 'AZUL_CA_PATH';

    // Hardcoded URLs used as defaults
    case SANDBOX_URL = 'https://pruebas.azul.com.do/WebServices/JSON/Default.aspx';
    case PROD_URL = 'https://pagos.azul.com.do/WebServices/JSON/Default.aspx';
    case PROD_FAILOVER_URL = 'https://contpagos.azul.com.do/WebServices/JSON/Default.aspx';

    case PAYMENT_PATH = '/WebServices/JSON/Default.aspx';
}
