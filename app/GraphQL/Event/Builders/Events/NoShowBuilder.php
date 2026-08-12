<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Builders\Events;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\TeeTime\Enums\EventStatusEnum;
use Kanvas\Event\Participants\Models\ParticipantPass;

class NoShowBuilder
{
    /**
     * Players who never checked in for a booking that already started.
     *
     * The grain is the pass, not the booking: a pass is issued per participant, while
     * `checkInWithPin` flips the whole Event to `validated` on the first scan. Counting bookings
     * by status would therefore report a foursome where three showed and one did not as fully
     * attended.
     */
    public function noShows(mixed $root, array $args): Builder
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $from = isset($args['from_date']) ? Carbon::parse((string) $args['from_date'])->startOfDay() : null;
        $to = isset($args['to_date']) ? Carbon::parse((string) $args['to_date'])->endOfDay() : null;

        return ParticipantPass::query()
            ->select('participant_passes.*')
            ->join('event_versions as ev', function ($join) {
                $join->on('ev.id', '=', 'participant_passes.event_version_id')
                    ->where('ev.is_deleted', 0);
            })
            ->leftJoin('events as e', 'e.id', '=', 'ev.event_id')
            ->leftJoin('event_statuses as es', 'es.id', '=', 'e.event_status_id')
            ->where('participant_passes.apps_id', $app->getId())
            ->where('participant_passes.companies_id', $company->getId())
            ->where('participant_passes.is_deleted', 0)
            ->whereNull('participant_passes.used_date')
            ->whereNotNull('participant_passes.participant_id')
            ->where('ev.start_at', '<', Carbon::now())
            ->when($from !== null, fn (Builder $q) => $q->where('ev.start_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('ev.start_at', '<=', $to))
            ->when(
                isset($args['resources_id']),
                fn (Builder $q) => $q->where('ev.time_slot_id', '!=', 0)
                    ->whereIn('ev.time_slot_id', function ($sub) use ($args) {
                        $sub->from('time_slots')
                            ->select('id')
                            ->where('resources_id', (int) $args['resources_id']);
                    })
            )
            // A cancelled tee time is not a no-show: nobody was expected to show up.
            // Matched on slug *or* name because `Setup` seeds statuses with a null slug ("Cancelled",
            // slug NULL) while the connector writes its own with one — filtering on slug alone lets
            // every cancelled booking through.
            ->where(
                fn (Builder $q) => $q->whereNull('e.event_status_id')
                    ->orWhereRaw(
                        'LOWER(COALESCE(NULLIF(es.slug, ""), es.name)) <> ?',
                        [EventStatusEnum::CANCELLED->value]
                    )
            )
            ->orderByDesc('ev.start_at');
    }
}
