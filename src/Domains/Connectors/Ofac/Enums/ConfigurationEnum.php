<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ofac\Enums;

enum ConfigurationEnum: string
{
    case OFAC_API_KEY = 'ofac_api_key';
    case OFAC_BASE_URL = 'OFAC_BASE_URL';
}
