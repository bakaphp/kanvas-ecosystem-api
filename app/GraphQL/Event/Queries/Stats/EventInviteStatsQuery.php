<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Stats;

use App\GraphQL\Concerns\ActingContext;
use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Support\Collection;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassRedemption;
use Kanvas\Event\Reports\Services\EventReportAccess;

class EventInviteStatsQuery
{
    use ResolvesActingContext;

    public function __invoke(mixed $root, array $args): array
    {
        $ctx = $this->actingContext();
        EventReportAccess::authorizeStats($ctx->user);

        $event = Event::getByIdFromCompanyApp((int) $args['event_id'], $ctx->company, $ctx->app);

        $codes = ParticipantPass::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->where('event_id', $event->getId())
            ->where('event_wide', true)
            ->whereNull('revoked_at')
            ->notDeleted();

        $bookingsBySlot = EventVersion::query()
            ->live()
            ->whereIn(
                'id',
                ParticipantPassRedemption::query()
                    ->select('booking_event_version_id')
                    ->whereIn('participant_passes_id', (clone $codes)->select('id'))
            )
            ->groupBy('time_slot_id')
            ->selectRaw('time_slot_id, COUNT(*) as bookings')
            ->pluck('bookings', 'time_slot_id');

        $slots = TimeSlots::query()
            ->whereKey($bookingsBySlot->keys()->filter())
            ->with('resource')
            ->get()
            ->keyBy('id');

        $bookingsByDay = $bookingsBySlot
            ->mapToGroups(fn (mixed $bookings, int|string $slotId): array => [
                $slots->get($slotId)?->localDate() ?? '' => (int) $bookings,
            ])
            ->map->sum();

        return [
            'codes_issued' => (clone $codes)->count(),
            'uses_issued' => (int) (clone $codes)->sum('max_uses'),
            'uses_redeemed' => $bookingsByDay->sum(),
            'days' => $this->days($event, $ctx)->map(fn (string $date): array => [
                'date' => $date,
                'bookings' => (int) ($bookingsByDay[$date] ?? 0),
            ])->all(),
        ];
    }

    private function days(Event $event, ActingContext $ctx): Collection
    {
        return EventVersion::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->daysOfEvent($event->getId())
            ->get()
            ->map(fn (EventVersion $day) => $day->start_at->toDateString());
    }
}
