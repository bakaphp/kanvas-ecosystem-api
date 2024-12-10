<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Builders\Events;

use Baka\Enums\StateEnums;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Events\Models\EventVersionParticipantDate;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Guild\Customers\Models\People;

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

    public function getHasEventVersion(mixed $root, array $args): Builder
    {
        $eventVersionParticipant = EventVersionParticipant::getFullTableName();
        $participant = Participant::getFullTableName();

        $peoples = People::select('*')
            ->join($participant, 'peoples.id', '=', $participant . '.people_id')
            ->join($eventVersionParticipant, $participant . '.id', '=', $eventVersionParticipant . '.participant_id')
            ->distinct();

        if (isset($args['HAS']['conditions'])) {
            foreach ($args['HAS']['conditions'] as $key => $condition) {
                $column = $condition['column'] ?? null;
                $value = $condition['value'] ?? null;

                if ($column && $value) {
                    $peoples->when($value, fn($query) => 
                        $query->where($eventVersionParticipant . '.' . $column, $value)
                    );
                }
            }
        }
        return $peoples;
    }
}
