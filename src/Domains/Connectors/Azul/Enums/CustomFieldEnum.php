<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Enums;

enum CustomFieldEnum: string
{
    case AZUL_ORDER_ID = 'azul_order_id';
    case AZUL_DATA_VAULT_TOKEN = 'azul_data_vault_token';
    case AZUL_AUTHORIZATION_CODE = 'azul_authorization_code';
    case AZUL_TICKET = 'azul_ticket';
    case AZUL_RRN = 'azul_rrn';
    case AZUL_LOT_NUMBER = 'azul_lot_number';
    case AZUL_3DS_PA_RES = 'azul_3ds_pa_res';
}
