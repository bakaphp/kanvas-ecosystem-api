<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Builders\Events;

use App\GraphQL\Concerns\ResolvesActingContext;
use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassScan;
use Kanvas\Event\Reports\Services\EventReportAccess;
use Kanvas\Inventory\Products\Models\Products;

class EventBookingBuilder
{
    use ResolvesActingContext;

    public function getBookings(mixed $root, array $args): Builder
    {
        $ctx = $this->actingContext();
        EventReportAccess::authorizeBookings($ctx->user);

        $event = Event::getByIdFromCompanyApp((int) $args['event_id'], $ctx->company, $ctx->app);
        $product = isset($args['product_id']) ? Products::getById((int) $args['product_id'], $ctx->app) : null;

        return EventVersion::query()
            ->select('event_versions.*')
            ->selectSub($this->passCodeOf(), 'pass_code_encrypted')
            ->selectSub($this->scanColumn('scanned_at'), 'admitted_at_utc')
            ->selectSub($this->scanColumn('source'), 'admitted_source')
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->live()
            ->whereIn('event_versions.id', $this->bookingsOfTheEvent($event, $ctx->app, $ctx->company))
            ->whereNotNull('time_slot_id')
            ->when($product !== null, fn (Builder $query) => $query->whereHas(
                'timeSlot',
                fn (Builder $slot) => $slot->forProduct($product)
            ))
            ->when(isset($args['date']), fn (Builder $query) => $query->whereHas(
                'timeSlot',
                fn (Builder $slot) => $slot->onLocalDay(
                    (string) $args['date'],
                    $this->timezoneOf($product, $ctx->company)
                )
            ))
            ->when(
                isset($args['reference']),
                fn (Builder $query) => $query->byReference((string) $args['reference'])
            )
            ->with([
                'participants.people',
                'timeSlot.resource',
            ])
            ->orderBy('start_at')
            ->orderBy('event_versions.id');
    }

    private function bookingsOfTheEvent(Event $event, AppInterface $app, Companies $company): Builder
    {
        return ParticipantPass::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('event_id', $event->getId())
            ->notDeleted()
            ->join(
                'participant_pass_redemptions',
                'participant_pass_redemptions.participant_passes_id',
                '=',
                'participant_passes.id'
            )
            ->select('participant_pass_redemptions.booking_event_version_id');
    }

    private function passCodeOf(): Builder
    {
        return ParticipantPass::query()
            ->select('code')
            ->whereColumn('event_version_id', 'event_versions.id')
            ->activeBookingPass()
            ->limit(1);
    }

    private function scanColumn(string $column): Builder
    {
        return ParticipantPassScan::query()
            ->select($column)
            ->whereColumn('admitted_event_version_id', 'event_versions.id')
            ->limit(1);
    }

    private function timezoneOf(?Products $product, Companies $company): string
    {
        return $product !== null
            ? ResourceTimezoneService::resolve($product)
            : $company->getTimezone() ?? ResourceTimezoneService::FALLBACK;
    }
}
