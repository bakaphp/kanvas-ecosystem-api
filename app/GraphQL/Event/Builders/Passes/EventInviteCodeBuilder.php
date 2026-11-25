<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Builders\Passes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Passes\Enums\InviteCodeStatusEnum;

class EventInviteCodeBuilder
{
    use ResolvesActingContext;

    public function getInviteCodes(mixed $root, array $args): Builder
    {
        $ctx = $this->actingContext();

        $event = Event::getByIdFromCompanyApp((int) $args['event_id'], $ctx->company, $ctx->app);

        return ParticipantPass::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->where('event_id', $event->getId())
            ->where('event_wide', true)
            ->notDeleted()
            ->withInviteStatus(isset($args['status']) ? InviteCodeStatusEnum::from($args['status']) : null)
            ->withCount(['redeemedBookings as live_uses' => fn (Builder $booking) => $booking->live()])
            ->orderBy('id');
    }
}
