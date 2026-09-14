<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Enums;

enum ConfigurationEnum: string
{
    case URL = 'odoo_url';
    case DATABASE = 'odoo_db';
    case USERNAME = 'odoo_username';
    case API_KEY = 'odoo_api_key';
}
