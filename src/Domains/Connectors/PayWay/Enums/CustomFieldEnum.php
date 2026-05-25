<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PayWay\Enums;

enum CustomFieldEnum: string
{
    case PAYWAY_NUMERO_UUID = 'PAYWAY_NUMERO_UUID';
    case PAYWAY_NUMERO_COMPRA = 'PAYWAY_NUMERO_COMPRA';
    case PAYWAY_NUMERO_PAYWAY = 'PAYWAY_NUMERO_PAYWAY';
    case PAYWAY_NUMERO_AUTORIZACION = 'PAYWAY_NUMERO_AUTORIZACION';
}
