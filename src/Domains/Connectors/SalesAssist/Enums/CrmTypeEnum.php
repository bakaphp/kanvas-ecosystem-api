<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Enums;

use Kanvas\Connectors\Elead\Services\EleadCrmDriver;
use Kanvas\Connectors\Reynolds\Services\ReynoldsCrmDriver;
use Kanvas\Connectors\SalesAssist\Contracts\CrmLeadDriver;

/**
 * The CRMs a lead sync can talk about. A new CRM is a case here, which is the value a request sends as
 * `identity.type`, and a driver class. The driver is built fresh on every call: it has no state, and
 * a cached instance would outlive a request under Octane.
 */
enum CrmTypeEnum: string
{
    case REYNOLDS = 'reynolds';
    case ELEAD = 'elead';

    public function driver(): CrmLeadDriver
    {
        return match ($this) {
            self::REYNOLDS => new ReynoldsCrmDriver(),
            self::ELEAD => new EleadCrmDriver(),
        };
    }
}
