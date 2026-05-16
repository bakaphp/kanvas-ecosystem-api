<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Queries;

use Kanvas\NervousSystem\Ledger\Services\NervousSystemHealthService;

class NervousSystemHealthQuery
{
    /**
     * @return array{
     *     writes_per_second: float,
     *     queue_lag_seconds: int,
     *     queue_depth: int,
     *     dead_letter_count: int,
     *     last_archive_at: \Illuminate\Support\Carbon|null,
     *     archive_lag_hours: int,
     *     hot_event_count: int,
     *     archived_event_count: int,
     *     sampled_redis_dedupe_keys: int,
     * }
     */
    public function get(mixed $rootValue, array $args): array
    {
        return new NervousSystemHealthService()->snapshot();
    }
}
