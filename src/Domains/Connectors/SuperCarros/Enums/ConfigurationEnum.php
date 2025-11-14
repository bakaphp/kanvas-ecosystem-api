<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'SuperCarros';
    case BASE_URL = 'super_carros_base_url';
    case ACCESS_KEY = 'super_carros_access_key';
    case CUSTOMER_ID = 'super_carros_customer_id';
}
