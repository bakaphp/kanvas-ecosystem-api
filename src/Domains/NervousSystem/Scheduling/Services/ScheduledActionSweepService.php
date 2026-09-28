<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;

/**
 * The claim + reaper side of the sweep. Kept tiny and deterministic: one bounded, indexed query per
 * tick claims due rows atomically so overlapping ticks / multiple sweeper replicas can never grab the
 * same row; a second query reclaims rows a crashed worker left stuck in `executing`.
 */
class ScheduledActionSweepService
{
    public const int BATCH_SIZE = 500;
    public const int STALE_CLAIM_MINUTES = 15;

    /**
     * Atomically claim up to `$limit` due rows: SELECT the oldest-due ids FOR UPDATE SKIP LOCKED, flip
     * them to `executing`, and return the claimed models. Runs in a short transaction so the row locks
     * are held only for the claim, not the fire.
     *
     * @return Collection<int, ScheduledAction>
     */
    public function claimDue(int $limit = self::BATCH_SIZE): Collection
    {
        return DB::connection('intelligence')->transaction(function () use ($limit): Collection {
            $ids = ScheduledAction::query()
                ->due()
                ->orderBy('run_at')
                ->limit($limit)
                ->lock('for update skip locked')
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return new Collection();
            }

            ScheduledAction::query()
                ->whereIn('id', $ids)
                ->update([
                    'status' => ScheduledActionStatusEnum::EXECUTING->value,
                    'claimed_at' => Carbon::now(),
                ]);

            return ScheduledAction::query()
                    ->whereIn('id', $ids)
                    ->orderBy('run_at')
                    ->get();
        });
    }

    public function reclaimStale(): int
    {
        return ScheduledAction::query()
            ->where('is_deleted', 0)
            ->where('status', ScheduledActionStatusEnum::EXECUTING->value)
            ->where('claimed_at', '<', Carbon::now()->subMinutes(self::STALE_CLAIM_MINUTES))
            ->update([
                'status' => ScheduledActionStatusEnum::PENDING->value,
                'claimed_at' => null,
            ]);
    }
}
