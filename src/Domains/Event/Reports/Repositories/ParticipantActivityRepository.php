<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Reports\DataTransferObject\ParticipantActivity;

class ParticipantActivityRepository
{
    /**
     * Activity per participant — the player, not whoever placed the booking. In golf one person
     * books for the whole foursome, so a booker-grained ranking hides the regular who never books.
     *
     * Same shape as OrganizationEventActivityRepository, one grain down.
     *
     * @return array{
     *     total:int, new_count:int, returning_count:int,
     *     rows:array<int, ParticipantActivity>
     * }
     */
    public static function activity(
        AppInterface $app,
        CompanyInterface $company,
        ?Carbon $fromDate,
        ?Carbon $toDate,
        ?int $topN = null,
    ): array {
        $stats = self::buildParticipantStats($app, $company);

        $fromStr = $fromDate?->toDateString();
        $toStr = $toDate?->toDateString();

        $active = [];

        foreach ($stats as $participantId => $s) {
            $countInWindow = 0;
            $firstInWindow = null;
            $lastInWindow = null;

            foreach ($s['dates'] as $date) {
                if ($fromStr !== null && $date < $fromStr) {
                    continue;
                }
                if ($toStr !== null && $date > $toStr) {
                    continue;
                }

                $countInWindow++;
                $firstInWindow = self::minDate($firstInWindow, $date);
                $lastInWindow = self::maxDate($lastInWindow, $date);
            }

            if ($countInWindow === 0) {
                continue;
            }

            // "New" means the window holds their first ever booking. Without a window every
            // player is a returning one by definition, so the split only means something when
            // a from_date is given.
            $hadPrior = $fromStr !== null && $s['first_ever'] !== null && $s['first_ever'] < $fromStr;

            $active[(int) $participantId] = new ParticipantActivity(
                participant_id: (int) $participantId,
                name: '',
                email: null,
                count: $countInWindow,
                first_event_date: $firstInWindow,
                last_event_date: $lastInWindow,
                had_prior_activity: $hadPrior,
            );
        }

        $rows = collect($active)->sortByDesc(fn (ParticipantActivity $r) => $r->count)->values();

        $newCount = $rows->filter(fn (ParticipantActivity $r) => ! $r->had_prior_activity)->count();

        $ranked = $topN !== null ? $rows->take($topN) : $rows;

        return [
            'total' => $rows->count(),
            'new_count' => $newCount,
            'returning_count' => $rows->count() - $newCount,
            'rows' => self::withIdentities($ranked->all()),
        ];
    }

    /**
     * Every participation of the tenant keyed by participant, with the booking dates it took part
     * in. `event_versions.start_at` is the tee time itself, which is what a booking is scheduled on.
     *
     * @return array<int, array{dates:array<int, string>, first_ever:?string}>
     */
    private static function buildParticipantStats(AppInterface $app, CompanyInterface $company): array
    {
        $rows = DB::connection('event')
            ->table('event_version_participants as evp')
            ->join('event_versions as ev', function ($join) {
                $join->on('ev.id', '=', 'evp.event_version_id')
                    ->where('ev.is_deleted', 0);
            })
            ->join('participants as p', function ($join) use ($app, $company) {
                $join->on('p.id', '=', 'evp.participant_id')
                    ->where('p.is_deleted', 0)
                    ->where('p.apps_id', $app->getId())
                    ->where('p.companies_id', $company->getId());
            })
            ->where('evp.is_deleted', 0)
            ->whereNotNull('ev.start_at')
            ->select(['evp.participant_id', 'ev.start_at'])
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $participantId = (int) $row->participant_id;
            $date = Carbon::parse((string) $row->start_at)->toDateString();

            if (! isset($stats[$participantId])) {
                $stats[$participantId] = ['dates' => [], 'first_ever' => null];
            }

            $stats[$participantId]['dates'][] = $date;
            $stats[$participantId]['first_ever'] = self::minDate($stats[$participantId]['first_ever'], $date);
        }

        return $stats;
    }

    /**
     * Names and emails live on the `crm` connection, so they are resolved for the returned page
     * only rather than joined across databases for the whole tenant.
     *
     * @param  array<int, ParticipantActivity>  $rows
     * @return array<int, ParticipantActivity>
     */
    private static function withIdentities(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $participants = Participant::with('people.contacts')
            ->whereIn('id', array_map(fn (ParticipantActivity $r) => $r->participant_id, $rows))
            ->get()
            ->keyBy('id');

        return array_map(function (ParticipantActivity $row) use ($participants) {
            $people = $participants->get($row->participant_id)?->people;

            return new ParticipantActivity(
                participant_id: $row->participant_id,
                name: $people?->getName() ?? '',
                email: $people?->getEmails()->first()?->value,
                count: $row->count,
                first_event_date: $row->first_event_date,
                last_event_date: $row->last_event_date,
                had_prior_activity: $row->had_prior_activity,
            );
        }, $rows);
    }

    private static function minDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a < $b ? $a : $b;
    }

    private static function maxDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a > $b ? $a : $b;
    }
}
