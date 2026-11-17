<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Concerns;

use Illuminate\Support\Carbon;

trait SchedulesRetries
{
    /**
     * @param int $attempt the attempt that just failed, counted from 1
     * @param non-empty-list<int> $minutes the wait after each attempt; past the last, the last wait
     *                                     repeats and the sweep keeps retrying at that pace
     */
    private function retryAt(int $attempt, array $minutes): Carbon
    {
        return now()->addMinutes($minutes[max(1, min($attempt, count($minutes))) - 1]);
    }
}
