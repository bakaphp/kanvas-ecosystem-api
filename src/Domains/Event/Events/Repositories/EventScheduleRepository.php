<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

class EventScheduleRepository
{
    private const string CONNECTION = 'event';

    /**
     * Raw busy windows in the [$from, $to] range, scoped by app/company.
     * If $user is given, restricts to that user. Otherwise returns all
     * scheduled events for the company (any user/facilitator).
     *
     * @return Collection<int, object{user_id:int, start:Carbon, end:Carbon, event_id:int, event_version_id:int, event_name:string}>
     */
    public function getScheduled(
        Apps $app,
        Companies $company,
        Carbon $from,
        Carbon $to,
        ?Users $user = null,
    ): Collection {
        $query = DB::connection(self::CONNECTION)
            ->table('event_version_dates as d')
            ->join('event_versions as v', 'v.id', '=', 'd.event_version_id')
            ->join('events as e', 'e.id', '=', 'v.event_id')
            ->where('d.is_deleted', 0)
            ->where('v.is_deleted', 0)
            ->where('e.is_deleted', 0)
            ->where('v.apps_id', $app->getId())
            ->where('v.companies_id', $company->getId())
            ->whereBetween('d.event_date', [
                $from->copy()->startOfDay()->toDateString(),
                $to->copy()->endOfDay()->toDateString(),
            ])
            ->select(
                'd.users_id as user_id',
                'd.event_date',
                'd.start_time',
                'd.end_time',
                'e.id as event_id',
                'v.id as event_version_id',
                'e.name as event_name',
            );

        if ($user !== null) {
            $query->where('d.users_id', $user->getId());
        }

        $tz = $from->getTimezone();

        return $query->get()->map(function (object $row) use ($tz): object {
            $date = Carbon::parse($row->event_date, $tz)->format('Y-m-d');
            $start = Carbon::parse($date . ' ' . $row->start_time, $tz);
            $end = Carbon::parse($date . ' ' . $row->end_time, $tz);

            return (object) [
                'user_id' => (int) $row->user_id,
                'start' => $start,
                'end' => $end,
                'event_id' => (int) $row->event_id,
                'event_version_id' => (int) $row->event_version_id,
                'event_name' => (string) $row->event_name,
            ];
        });
    }

    /**
     * Compute available time slots for $user within [$from, $to], honoring
     * $workingHours and excluding any busy windows from getScheduled().
     *
     * $workingHours accepts either:
     *   - Simple:  ['opens_at_local' => '09:00:00', 'closes_at_local' => '17:00:00']
     *   - Weekly:  ['monday' => '09:00-17:00', 'tuesday' => '09:00-17:00', ...]
     *
     * Returns up to $limit slots ordered by start time. Times are ISO-8601 in the
     * timezone of $from (caller normalizes to the company timezone).
     *
     * @param array<string, mixed> $workingHours
     * @return array<int, array{user_id:int, start:string, end:string}>
     */
    public function getAvailableSlotsForUser(
        Apps $app,
        Companies $company,
        Users $user,
        Carbon $from,
        Carbon $to,
        int $durationMinutes,
        array $workingHours,
        int $limit = 20,
        array $workingDays = [],
    ): array {
        if ($durationMinutes <= 0 || $from->gte($to)) {
            return [];
        }

        // Normalize the working-day allowlist to lowercase English day names so a
        // non-working day (e.g. weekend) is skipped instead of silently proposed.
        $workingDays = array_map(
            static fn (mixed $day): string => strtolower(trim((string) $day)),
            $workingDays,
        );

        $busy = $this->getScheduled($app, $company, $from, $to, $user)
            ->sortBy(fn (object $b): int => $b->start->timestamp)
            ->values();

        $busyByDate = $busy->groupBy(fn (object $b): string => $b->start->format('Y-m-d'));

        $slots = [];
        $cursor = $from->copy()->startOfDay();
        $userId = $user->getId();

        while ($cursor->lte($to) && count($slots) < $limit) {
            $dayName = strtolower($cursor->format('l'));

            if ($workingDays !== [] && ! in_array($dayName, $workingDays, true)) {
                $cursor->addDay();

                continue;
            }

            [$openTime, $closeTime] = $this->resolveDayHours($workingHours, $dayName);

            if ($openTime === null || $closeTime === null) {
                $cursor->addDay();

                continue;
            }

            $dayOpen = $cursor->copy()->setTimeFromTimeString($openTime);
            $dayClose = $cursor->copy()->setTimeFromTimeString($closeTime);

            if ($dayOpen->lt($from)) {
                $dayOpen = $from->copy();
            }
            if ($dayClose->gt($to)) {
                $dayClose = $to->copy();
            }
            if ($dayOpen->gte($dayClose)) {
                $cursor->addDay();

                continue;
            }

            $dateKey = $cursor->format('Y-m-d');
            $dayBusy = $busyByDate->get($dateKey) ?? collect();

            $slotStart = $dayOpen->copy();

            foreach ($dayBusy as $b) {
                while ($slotStart->copy()->addMinutes($durationMinutes)->lte($b->start)
                    && count($slots) < $limit
                ) {
                    $slotEnd = $slotStart->copy()->addMinutes($durationMinutes);
                    $slots[] = [
                        'user_id' => $userId,
                        'start' => $slotStart->toIso8601String(),
                        'end' => $slotEnd->toIso8601String(),
                    ];
                    $slotStart->addMinutes($durationMinutes);
                }

                if ($slotStart->lt($b->end)) {
                    $slotStart = $b->end->copy();
                }
            }

            while ($slotStart->copy()->addMinutes($durationMinutes)->lte($dayClose)
                && count($slots) < $limit
            ) {
                $slotEnd = $slotStart->copy()->addMinutes($durationMinutes);
                $slots[] = [
                    'user_id' => $userId,
                    'start' => $slotStart->toIso8601String(),
                    'end' => $slotEnd->toIso8601String(),
                ];
                $slotStart->addMinutes($durationMinutes);
            }

            $cursor->addDay();
        }

        return $slots;
    }

    /**
     * Resolve a day's open/close from the company WORKING_HOURS config.
     *
     * Mirrors the shapes the canonical CompanyWorkHoursTool accepts so the agent's
     * proposed slots line up with the hours it quotes: a single `opens_at_local` /
     * `closes_at_local` applied to every day, OR a per-day weekly map. Day keys are
     * matched case-insensitively ('Monday' or 'monday') and per-day values may be a
     * `"09:00-18:00"` string or an `{open,close}` / `{opens_at_local,closes_at_local}`
     * array. This used to assume a lowercase key with a string value and silently
     * returned `[null, null]` for the real config — which collapsed to 0 slots and the
     * agent telling prospects the owner was "fully booked".
     *
     * @param array<array-key, mixed> $workingHours
     * @return array{0:?string, 1:?string}
     */
    private function resolveDayHours(array $workingHours, string $dayName): array
    {
        if (isset($workingHours['opens_at_local'], $workingHours['closes_at_local'])) {
            return [
                $this->ensureSeconds((string) $workingHours['opens_at_local']),
                $this->ensureSeconds((string) $workingHours['closes_at_local']),
            ];
        }

        $hours = null;
        foreach ($workingHours as $key => $value) {
            if (is_string($key) && strtolower($key) === $dayName) {
                $hours = $value;

                break;
            }
        }

        if (is_array($hours)) {
            $open = $hours['open'] ?? $hours['opens_at_local'] ?? $hours['from'] ?? $hours['start'] ?? null;
            $close = $hours['close'] ?? $hours['closes_at_local'] ?? $hours['to'] ?? $hours['end'] ?? null;

            return ($open === null || $close === null)
                ? [null, null]
                : [$this->ensureSeconds((string) $open), $this->ensureSeconds((string) $close)];
        }

        if (! is_string($hours) || ! str_contains($hours, '-')) {
            return [null, null];
        }

        [$open, $close] = array_map('trim', explode('-', $hours, 2));

        return [$this->ensureSeconds($open), $this->ensureSeconds($close)];
    }

    private function ensureSeconds(string $time): string
    {
        $parts = explode(':', trim($time));

        return match (count($parts)) {
            1 => sprintf('%02d:00:00', (int) $parts[0]),
            2 => sprintf('%02d:%02d:00', (int) $parts[0], (int) $parts[1]),
            default => sprintf('%02d:%02d:%02d', (int) $parts[0], (int) $parts[1], (int) $parts[2]),
        };
    }
}
