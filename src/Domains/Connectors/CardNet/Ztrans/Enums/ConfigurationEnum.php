<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'cardnet_ztrans_base_url';
    case MERCHANT_ID = 'cardnet_ztrans_merchant_id';
    case TERMINAL_ID = 'cardnet_ztrans_terminal_id';
    case THREE_DS_BASE_URL = 'cardnet_ztrans_3ds_base_url';
    case THREE_DS_INTEGRATOR_CODE = 'cardnet_ztrans_3ds_integrator_code';
    case THREE_DS_API_KEY = 'cardnet_ztrans_3ds_api_key';
    case THREE_DS_MODE = 'cardnet_ztrans_3ds_mode';
    case USE_HOLD = 'cardnet_ztrans_use_hold';
    case REFUND_BEARER_TOKEN = 'cardnet_ztrans_refund_bearer_token';
}
