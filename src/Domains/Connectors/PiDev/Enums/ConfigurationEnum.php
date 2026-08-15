<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Enums;

enum ConfigurationEnum: string
{
    case NAME = 'PiDev';
    case BASE_URL = 'pidev_base_url';
    case API_TOKEN = 'pidev_api_token';
}
