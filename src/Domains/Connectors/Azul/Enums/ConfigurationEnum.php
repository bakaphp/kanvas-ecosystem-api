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
    // mTLS material. Each accepts either the PEM itself (raw, base64 or encrypted) or a
    // path to it — content is what survives a deploy, since the container filesystem does not.
    case AZUL_CERT = 'AZUL_CERT';           // Client certificate
    case AZUL_KEY = 'AZUL_KEY';             // Private key
    case AZUL_CA = 'AZUL_CA';               // CA bundle for server verification
    case AZUL_KEY_PASSWORD = 'AZUL_KEY_PASSWORD'; // Password for private key (optional)

    // Legacy path-only keys, still honoured when the ones above are unset.
    case AZUL_CERT_PATH = 'AZUL_CERT_PATH';
    case AZUL_KEY_PATH = 'AZUL_KEY_PATH';
    case AZUL_CA_PATH = 'AZUL_CA_PATH';
    case AZUL_VERIFY_SSL = 'AZUL_VERIFY_SSL'; // Whether to verify SSL (true/false)
    case AZUL_USE_HOLD = 'AZUL_USE_HOLD';     // Use Hold+Post (two-step) instead of immediate Sale
    case AZUL_DEBUG_LOG = 'AZUL_DEBUG_LOG';   // Enable detailed API request/response logging
    case AZUL_3DS_TERM_URL = 'AZUL_3DS_TERM_URL';         // TermUrl for 3DS redirect after challenge
    case AZUL_3DS_METHOD_NOTIFICATION_URL = 'AZUL_3DS_METHOD_NOTIFICATION_URL'; // MethodNotificationUrl for 3DS method data

    // Hardcoded URLs used as defaults
    case SANDBOX_URL = 'https://pruebas.azul.com.do/WebServices/JSON/Default.aspx';
    case PROD_URL = 'https://pagos.azul.com.do/WebServices/JSON/Default.aspx';
    case PROD_FAILOVER_URL = 'https://contpagos.azul.com.do/WebServices/JSON/Default.aspx';

    // API path
    case PAYMENT_PATH = '/WebServices/JSON/Default.aspx';
}
