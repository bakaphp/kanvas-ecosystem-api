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

    /**
     * App-wide kill switch, set only by hand: the mirror is permanent and bidirectional, so no code
     * sets it. From this instant the webhook, the scheduled pull and any import without --force stop
     * and no cursor advances past it; clearing it resumes them.
     */
    case BRUSHCRAZY_CUTOVER_AT = 'BRUSHCRAZY_CUTOVER_AT';

    /**
     * Rows starting at or after this instant get the studio-timezone correction. Pinned once by the
     * first import and never moved, so the import and the mirror keep applying the same boundary.
     * Kept apart from CUTOVER_AT: that one blocks imports, and pinning it at first import would
     * lock the import out of its own second run.
     */
    case BRUSHCRAZY_TZ_CORRECTION_CUTOFF = 'BRUSHCRAZY_TZ_CORRECTION_CUTOFF';

    /**
     * Write-back to BrushCrazy: the site sells in Kanvas and BrushCrazy's own API books the sale,
     * so its listeners (registrations, rewards, taxes) run. Signed like the mirror, in reverse.
     */
    case BRUSHCRAZY_API_URL = 'BRUSHCRAZY_API_URL';
    case BRUSHCRAZY_API_SECRET = 'BRUSHCRAZY_API_SECRET';
}
