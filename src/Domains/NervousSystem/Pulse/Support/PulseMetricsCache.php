<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Support;

use Kanvas\NervousSystem\Metrics\Support\AbstractMetricsCache;
use Override;

/**
 * Cache helper for the Pulse metrics resolver. The key prefix is the
 * only Pulse-specific piece — TTL, key shape, and forget() live in
 * AbstractMetricsCache.
 */
class PulseMetricsCache extends AbstractMetricsCache
{
    #[Override]
    public static function keyPrefix(): string
    {
        return 'pulse:metrics';
    }
}
