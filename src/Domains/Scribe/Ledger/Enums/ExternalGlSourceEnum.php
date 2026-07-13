<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Enums;

/**
 * ERP integrations that, when enabled on a company, take ownership of that company's general ledger.
 *
 * Each case is the COMPANY CONFIG KEY the connector sets when its sync is turned on. Held as raw strings
 * rather than importing the connectors' ConfigurationEnums — Scribe is the domain layer and must not depend
 * on Connectors. Adding an ERP means adding a case here.
 *
 * @see GlOwnershipService
 */
enum ExternalGlSourceEnum: string
{
    case ACUMATICA = 'ACUMATICA_SYNC_ENABLED';

    /**
     * The `source` value the connector stamps on the rows it imports.
     */
    public function documentSource(): string
    {
        return match ($this) {
            self::ACUMATICA => 'acumatica',
        };
    }
}
