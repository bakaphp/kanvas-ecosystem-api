<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Enums;

enum ConfigurationEnum: string
{
    case ACUMATICA_CONFIG = 'ACUMATICA_CONFIG';
    case ACUMATICA_DEFAULT_WAREHOUSE = 'ACUMATICA_DEFAULT_WAREHOUSE';
    case ACUMATICA_WRITE_ENABLED = 'ACUMATICA_WRITE_ENABLED';

    /**
     * Fallback subaccount code for an AP bill line when neither the Kanvas line carries one nor the
     * replica can derive the account's dominant historical subaccount. Tenants that make Subaccount
     * required on AP lines set this so a push never fails for a missing dimension.
     */
    case ACUMATICA_DEFAULT_SUBACCOUNT = 'ACUMATICA_DEFAULT_SUBACCOUNT';

    /**
     * TaxZone code to send on an AR Invoice/Credit Memo push when the Kanvas document has zero tax
     * (e.g. Germany's "NONTAX" zone). Without this, Acumatica falls back to the tenant's default tax
     * zone and silently adds VAT on top of a document Kanvas computed as tax-exempt.
     */
    case ACUMATICA_TAX_EXEMPT_ZONE = 'ACUMATICA_TAX_EXEMPT_ZONE';

    /**
     * Per-tenant required custom fields injected into a SalesOrder push, so a tenant's Acumatica
     * customizations (e.g. required Usr* order-date fields) are satisfied without hardcoding them in
     * the connector. Map of Acumatica field name → spec; a spec is `{days:int}` (order date + N days,
     * as a DateTime), a `{value:string}` literal, or a bare int/string shorthand for those. Optional
     * per-entry `type` (default DateTime for `days`, String for `value`) and `view` (default
     * `Document` — the SO header data view the fields hang off in contract-based REST).
     */
    case SO_CUSTOM_FIELDS = 'ACUMATICA_SO_CUSTOM_FIELDS';

    case SYNC_ENABLED = 'ACUMATICA_SYNC_ENABLED';

    // Company-scoped params the scheduler needs to build a pull for the enabled company.
    case SYNC_COMPANY_ID = 'ACUMATICA_COMPANY_ID';   // Acumatica legal-entity CompanyID
    case SYNC_APP_ID = 'ACUMATICA_SYNC_APP_ID';      // Kanvas app the config lives on
    case SYNC_USER_ID = 'ACUMATICA_SYNC_USER_ID';    // Kanvas user imports are attributed to
    case SYNC_REGION_ID = 'ACUMATICA_SYNC_REGION_ID';

    /** Prefix for the per-entity incremental high-watermark, suffixed with the entity key. */
    case SYNC_CURSOR_PREFIX = 'ACUMATICA_SYNC_CURSOR_';
}
