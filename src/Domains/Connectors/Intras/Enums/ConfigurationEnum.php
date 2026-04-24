<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Enums;

enum ConfigurationEnum: string
{
    case INTRAS_DB_HOST = 'INTRAS_DB_HOST';
    case INTRAS_DB_PORT = 'INTRAS_DB_PORT';
    case INTRAS_DB_DATABASE = 'INTRAS_DB_DATABASE';
    case INTRAS_DB_USERNAME = 'INTRAS_DB_USERNAME';
    case INTRAS_DB_PASSWORD = 'INTRAS_DB_PASSWORD';
    case INTRAS_SYNC_ENABLED = 'INTRAS_SYNC_ENABLED';
    case INTRAS_LAST_SYNC_AT = 'INTRAS_LAST_SYNC_AT';
}
