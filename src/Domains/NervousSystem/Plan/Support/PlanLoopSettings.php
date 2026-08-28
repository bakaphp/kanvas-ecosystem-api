<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Support;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Enums\PlanLoopConfigEnum;
use Throwable;

/**
 * Where the loop's flags are read, in one place so "is this on?" cannot drift between call sites.
 *
 * Off unless explicitly enabled. A config read that throws is treated as off rather than propagating:
 * a missing custom-field row must not take down a wake that would otherwise have worked the old way.
 */
class PlanLoopSettings
{
    /** Values that count as on. Config comes back as strings from custom fields and booleans from app settings. */
    private const array TRUTHY = [true, 1, '1', 'true', 'on', 'yes'];

    public static function continuationEnabled(?Agent $agent): bool
    {
        if (! $agent instanceof Agent) {
            return false;
        }

        $key = PlanLoopConfigEnum::CONTINUATION_ENABLED->value;

        try {
            $onAgent = $agent->get($key);

            if ($onAgent !== null && $onAgent !== '') {
                return in_array($onAgent, self::TRUTHY, true);
            }

            return in_array($agent->app->get($key), self::TRUTHY, true);
        } catch (Throwable) {
            return false;
        }
    }
}
