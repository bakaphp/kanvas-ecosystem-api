<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Enums;

enum CustomFieldEnum: string
{
    case AZUL_ORDER_ID = 'azul_order_id';
    case AZUL_DATA_VAULT_TOKEN = 'azul_data_vault_token';
    case AZUL_AUTHORIZATION_CODE = 'azul_authorization_code';
    case AZUL_TICKET = 'azul_ticket';
}
