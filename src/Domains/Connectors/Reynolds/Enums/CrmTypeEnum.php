<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Enums;

use Kanvas\Connectors\Reynolds\Contracts\CrmLeadDriver;
use Kanvas\Connectors\Reynolds\Services\ReynoldsCrmDriver;

/**
 * The CRMs a lead sync can talk about. A new CRM is a case here, which is the value a request sends as
 * `identity.type`, and a driver class. The driver is built fresh on every call: it has no state, and
 * a cached instance would outlive a request under Octane.
 */
enum CrmTypeEnum: string
{
    case REYNOLDS = 'reynolds';

    public function driver(): CrmLeadDriver
    {
        return match ($this) {
            self::REYNOLDS => new ReynoldsCrmDriver(),
        };
    }
}
