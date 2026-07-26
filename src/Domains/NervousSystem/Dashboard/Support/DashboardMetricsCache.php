<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Support;

use Kanvas\NervousSystem\Metrics\Support\AbstractMetricsCache;
use Override;

/**
 * Cache helper for the Dashboard metrics resolver. The key prefix is
 * the only Dashboard-specific piece — TTL, key shape, and forget() live
 * in AbstractMetricsCache.
 */
class DashboardMetricsCache extends AbstractMetricsCache
{
    #[Override]
    public static function keyPrefix(): string
    {
        return 'dashboard:metrics';
    }
}
