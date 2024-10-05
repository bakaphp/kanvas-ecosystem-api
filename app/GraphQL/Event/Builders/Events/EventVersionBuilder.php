<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Builders\Events;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Events\Models\EventVersionParticipantDate;

class EventVersionBuilder
{
    public function getEventVersion(mixed $root, array $args): Builder
    {
        $app = app(Apps::class);

        return EventVersion::fromApp($app)
                ->where('event_id', $root->id)
                ->where('is_deleted', StateEnums::NO->getValue());
    }

    public function getParticipants(mixed $root, array $args): Builder
    {
        return EventVersionParticipant::where('event_version_id', $root->id)
                ->where('is_deleted', StateEnums::NO->getValue());
    }

    public function getFacilitators(mixed $root, array $args): Builder
    {
        return EventVersionParticipant::where('event_version_id', $root->id)
                ->where('is_deleted', StateEnums::NO->getValue());
    }

    public function getParticipantAttendees(mixed $root, array $args): Builder
    {
        return EventVersionParticipantDate::where('event_version_id', $root->id)
                ->where('is_deleted', StateEnums::NO->getValue());
    }
}
