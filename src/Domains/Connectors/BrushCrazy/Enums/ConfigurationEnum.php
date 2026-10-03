<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Enums;

enum ConfigurationEnum: string
{
    case BRUSHCRAZY_DB_HOST = 'BRUSHCRAZY_DB_HOST';
    case BRUSHCRAZY_DB_PORT = 'BRUSHCRAZY_DB_PORT';
    case BRUSHCRAZY_DB_DATABASE = 'BRUSHCRAZY_DB_DATABASE';
    case BRUSHCRAZY_DB_USERNAME = 'BRUSHCRAZY_DB_USERNAME';
    case BRUSHCRAZY_DB_PASSWORD = 'BRUSHCRAZY_DB_PASSWORD';

    /**
     * Path to the RDS CA bundle. The source app sets MYSQL_ATTR_SSL_CA, so the instance may
     * require TLS — without this the connection is rejected once the network path is open.
     */
    case BRUSHCRAZY_DB_SSL_CA = 'BRUSHCRAZY_DB_SSL_CA';
    case BRUSHCRAZY_SYNC_ENABLED = 'BRUSHCRAZY_SYNC_ENABLED';
    case BRUSHCRAZY_STUDIO_MODE = 'BRUSHCRAZY_STUDIO_MODE';
    case BRUSHCRAZY_MEDIA_BASE_URL = 'BRUSHCRAZY_MEDIA_BASE_URL';
    case BRUSHCRAZY_DEFAULT_TIMEZONE = 'BRUSHCRAZY_DEFAULT_TIMEZONE';
    case BRUSHCRAZY_DEFAULT_CURRENCY = 'BRUSHCRAZY_DEFAULT_CURRENCY';
    case BRUSHCRAZY_IMPORT_CURSOR = 'BRUSHCRAZY_IMPORT_CURSOR';

    /**
     * Set at cutover. Any import invoked afterwards refuses to run without --force and never
     * advances a cursor past it, so a stale run can't clobber Kanvas-native data weeks later.
     */
    case BRUSHCRAZY_CUTOVER_AT = 'BRUSHCRAZY_CUTOVER_AT';
}
