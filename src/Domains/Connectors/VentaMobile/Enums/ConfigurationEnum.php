<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'VentaMobile';
    case BASE_URL = 'venta_mobile_base_url';
    case USERNAME = 'venta_mobile_username';
    case PASSWORD = 'venta_mobile_password';
}
