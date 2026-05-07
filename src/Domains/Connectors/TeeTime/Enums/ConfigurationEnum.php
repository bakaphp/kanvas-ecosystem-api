<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TeeTime\Enums;

enum ConfigurationEnum: string
{
    case BASE_URL = 'base_url';
    case CLIENT_ID = 'client_id';
    case SECRET = 'secret';
}
