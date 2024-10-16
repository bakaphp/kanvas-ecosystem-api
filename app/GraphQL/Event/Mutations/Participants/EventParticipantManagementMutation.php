<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Participants;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Actions\SyncPeopleWithParticipantAction;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Guild\Customers\Models\People;

class EventParticipantManagementMutation
{
    public function addPeopleToEventVersion(mixed $root, _uarray $req): Participant
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $input = $req['input'];

        $eventVersion = EventVersion::getByIdFromCompanyApp($input['event_version_id'], $user->getCurrentCompany(), $app);
        $people = People::getByIdFromCompanyApp($input['people_id'], $user->getCurrentCompany(), $app);
        //$event->addPeopleToEventVersion($req['input']['people_id'], $req['input']['event_version_id']);

        $syncParticipant = new SyncPeopleWithParticipantAction($people, $user);
        $participant = $syncParticipant->execute();

        //@todo move to action
        $eventVersion->addParticipant($participant);

        return $participant;
    }
}
