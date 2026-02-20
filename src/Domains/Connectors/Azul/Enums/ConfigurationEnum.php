<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Enums;

enum ConfigurationEnum: string
{
    // Stored in app custom fields
    case AZUL_AUTH1 = 'AZUL_AUTH1';
    case AZUL_AUTH2 = 'AZUL_AUTH2';
    case AZUL_STORE = 'AZUL_STORE';
    case AZUL_CHANNEL = 'AZUL_CHANNEL';
    case AZUL_BASE_URL = 'AZUL_BASE_URL';
    case AZUL_FAILOVER_URL = 'AZUL_FAILOVER_URL';
    case AZUL_CERT_PATH = 'AZUL_CERT_PATH'; // Path to client certificate file (mTLS)
    case AZUL_KEY_PATH = 'AZUL_KEY_PATH';   // Path to private key file (mTLS)

    // Hardcoded URLs used as defaults
    case SANDBOX_URL = 'https://pruebas.azul.com.do/WebServices/JSON/Default.aspx';
    case PROD_URL = 'https://pagos.azul.com.do/WebServices/JSON/Default.aspx';
    case PROD_FAILOVER_URL = 'https://contpagos.azul.com.do/WebServices/JSON/Default.aspx';

    // API path
    case PAYMENT_PATH = '/WebServices/JSON/Default.aspx';
}
