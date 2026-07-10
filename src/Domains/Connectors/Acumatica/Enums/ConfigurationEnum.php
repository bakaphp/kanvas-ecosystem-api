<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Enums;

enum ConfigurationEnum: string
{
    case ACUMATICA_CONFIG = 'ACUMATICA_CONFIG';
    case ACUMATICA_DEFAULT_WAREHOUSE = 'ACUMATICA_DEFAULT_WAREHOUSE';
    case ACUMATICA_WRITE_ENABLED = 'ACUMATICA_WRITE_ENABLED';

    /**
     * Per-company gate for the scheduled sync. The scheduler NEVER touches a company unless this is
     * truthy — enablement is opt-in, set per legal entity via kanvas:acumatica-enable-sync.
     */
    case SYNC_ENABLED = 'ACUMATICA_SYNC_ENABLED';

    // Company-scoped params the scheduler needs to build a pull for the enabled company.
    case SYNC_COMPANY_ID = 'ACUMATICA_COMPANY_ID';   // Acumatica legal-entity CompanyID
    case SYNC_APP_ID = 'ACUMATICA_SYNC_APP_ID';      // Kanvas app the config lives on
    case SYNC_USER_ID = 'ACUMATICA_SYNC_USER_ID';    // Kanvas user imports are attributed to
    case SYNC_REGION_ID = 'ACUMATICA_SYNC_REGION_ID';

    /** Prefix for the per-entity incremental high-watermark, suffixed with the entity key. */
    case SYNC_CURSOR_PREFIX = 'ACUMATICA_SYNC_CURSOR_';
}
