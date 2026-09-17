<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Exceptions;

use Exception;

class DriveCentricException extends Exception
{
    /**
     * Statuses where DriveCentric rejected the record itself, so it's a data problem to log rather than
     * a fault to report. 0 = thrown locally (e.g. customer not synced yet). Anything unlisted — auth
     * (401/403), rate limits (429), 5xx — stays reportable so expired credentials and outages reach Sentry.
     */
    private const array DATA_REJECTION_STATUSES = [0, 400, 404, 409, 422];

    public function isDataRejection(): bool
    {
        return in_array($this->getCode(), self::DATA_REJECTION_STATUSES, true);
    }
}
