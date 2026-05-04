<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Kanvas\NervousSystem\Ledger\Enums\LedgerQueueEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Ledger\Models\EventArchive;
use Throwable;

/**
 * Best-effort: a Redis failure returns 0 for the affected metric instead
 * of throwing — ops dashboards prefer a partial answer over a 500.
 */
class NervousSystemHealthService
{
    /**
     * @return array{
     *     writes_per_second: float,
     *     queue_lag_seconds: int,
     *     queue_depth: int,
     *     dead_letter_count: int,
     *     last_archive_at: Carbon|null,
     *     archive_lag_hours: int,
     *     hot_event_count: int,
     *     archived_event_count: int,
     *     sampled_redis_dedupe_keys: int,
     * }
     */
    public function snapshot(): array
    {
        return [
            'writes_per_second' => $this->writesPerSecond(),
            'queue_lag_seconds' => $this->queueLagSeconds(),
            'queue_depth' => $this->queueDepth(),
            'dead_letter_count' => $this->deadLetterCount(),
            'last_archive_at' => $this->lastArchiveAt(),
            'archive_lag_hours' => $this->archiveLagHours(),
            'hot_event_count' => $this->hotEventCount(),
            'archived_event_count' => $this->archivedEventCount(),
            'sampled_redis_dedupe_keys' => $this->sampledDedupeKeyCount(),
        ];
    }

    private function writesPerSecond(): float
    {
        $window = 60;
        $count = Event::query()
            ->where('occurred_at', '>=', Carbon::now()->subSeconds($window))
            ->count();

        return round($count / $window, 3);
    }

    /**
     * Approximate queue lag — the gap between the oldest queued event's
     * intended occurred_at and now. We use indexed_at as a proxy for the
     * row's wall-clock arrival; if the most recent row in the table was
     * indexed long ago, the queue is backed up.
     */
    private function queueLagSeconds(): int
    {
        $latestIndexedAt = Event::query()->max('indexed_at');

        if ($latestIndexedAt === null) {
            return 0;
        }

        $diff = Carbon::now()->diffInSeconds(Carbon::parse($latestIndexedAt));

        return max(0, (int) $diff);
    }

    private function queueDepth(): int
    {
        try {
            return Queue::size(LedgerQueueEnum::LEDGER->value);
        } catch (Throwable) {
            return 0;
        }
    }

    private function deadLetterCount(): int
    {
        return DB::table('failed_jobs')
            ->where('queue', LedgerQueueEnum::LEDGER->value)
            ->count();
    }

    private function lastArchiveAt(): ?Carbon
    {
        $latest = EventArchive::query()->max('archived_at');

        return $latest !== null ? Carbon::parse($latest) : null;
    }

    private function archiveLagHours(): int
    {
        $last = $this->lastArchiveAt();

        if ($last === null) {
            return 0;
        }

        return max(0, (int) Carbon::now()->diffInHours($last));
    }

    private function hotEventCount(): int
    {
        return Event::query()->count();
    }

    private function archivedEventCount(): int
    {
        return (int) (EventArchive::query()->sum('event_count') ?? 0);
    }

    /**
     * Sampled count of `ns:dedupe:*` keys currently in Redis. Uses SCAN
     * with a cap to avoid blocking; the result is illustrative, not exact.
     */
    private function sampledDedupeKeyCount(): int
    {
        try {
            $cursor = '0';
            $count = 0;
            $iterations = 0;

            do {
                $result = Redis::scan($cursor, ['match' => 'ns:dedupe:*', 'count' => 1000]);
                if (! is_array($result) || count($result) !== 2) {
                    break;
                }
                [$cursor, $keys] = $result;
                $count += is_array($keys) ? count($keys) : 0;
                $iterations++;
            } while ((string) $cursor !== '0' && $iterations < 5);

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }
}
