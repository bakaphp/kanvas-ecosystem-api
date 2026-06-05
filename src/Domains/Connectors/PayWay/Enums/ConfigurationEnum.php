<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PayWay\Enums;

enum ConfigurationEnum: string
{
    case PAYWAY_TOKEN = 'payway_token';
    case PAYWAY_COLECTOR_ID = 'payway_colector_id';
    case PAYWAY_USUARIO_OPERACION = 'payway_usuario_operacion';
    case PAYWAY_ENCRYPTION_KEY = 'payway_encryption_key';
    case PAYWAY_BASE_URL = 'payway_base_url';
    case PAYWAY_VERIFY_CHARGE_AMOUNT = 'payway_verify_charge_amount';
    case PAYWAY_TOKENIZE_CUTOFF_LOCAL_TIME = 'payway_tokenize_cutoff_local_time';
}
