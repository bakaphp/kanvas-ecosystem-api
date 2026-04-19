<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Participants;

use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Participants\Actions\SyncPeopleWithParticipantAction;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Guild\Customers\Models\People;

class EventParticipantManagementMutation
{
    public function addPeopleToEventVersion(mixed $root, array $req): Participant
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var EventVersion $eventVersion */
        $eventVersion = EventVersion::getByIdFromCompanyApp((int) $input['event_version_id'], $company, $app);
        /** @var People $people */
        $people = People::getByIdFromCompanyApp((int) $input['people_id'], $company, $app);

        $syncParticipant = new SyncPeopleWithParticipantAction($people, $user);
        $participant = $syncParticipant->execute();

        $eventVersionParticipant = $eventVersion->addParticipant($participant);

        // Apply optional pivot fields (ticket_price, discount, invoice_date, metadata, participant_type)
        $pivotDirty = false;

        if (! empty($input['participant_type_id'])) {
            $participantType = ParticipantType::getByIdFromCompanyApp(
                (int) $input['participant_type_id'],
                $company,
                $app,
            );
            $eventVersionParticipant->participant_type_id = $participantType->getId();
            $pivotDirty = true;
        }

        foreach (['ticket_price', 'discount', 'invoice_date'] as $key) {
            if (array_key_exists($key, $input)) {
                $eventVersionParticipant->{$key} = $input[$key];
                $pivotDirty = true;
            }
        }

        if (! empty($input['metadata']) && is_array($input['metadata'])) {
            $existing = is_array($eventVersionParticipant->metadata) ? $eventVersionParticipant->metadata : [];
            $eventVersionParticipant->metadata = array_merge($existing, $input['metadata']);
            $pivotDirty = true;
        }

        if ($pivotDirty) {
            $eventVersionParticipant->saveOrFail();
        }

        // Custom fields / files / tags apply to the Participant record
        if (! empty($input['custom_fields']) && is_array($input['custom_fields'])) {
            $participant->setAllCustomFields($input['custom_fields']);
        }

        if (! empty($input['files']) && is_array($input['files'])) {
            $participant->addMultipleFilesFromUrl($input['files']);
        }

        if (array_key_exists('tags', $input) && is_array($input['tags'])) {
            $tagNames = [];
            foreach ($input['tags'] as $tag) {
                if (is_array($tag) && isset($tag['name'])) {
                    $tagNames[] = (string) $tag['name'];
                } elseif (is_string($tag)) {
                    $tagNames[] = $tag;
                }
            }
            if (! empty($tagNames)) {
                $participant->syncTags($tagNames);
            }
        }

        return $participant;
    }

    public function removePeopleFromEventVersion(mixed $root, array $req): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $input = $req['input'];

        $eventVersion = EventVersion::getByIdFromCompanyApp($input['event_version_id'], $user->getCurrentCompany(), $app);
        $people = People::getByIdFromCompanyApp($input['people_id'], $user->getCurrentCompany(), $app);
        //$event->removePeopleFromEventVersion($req['input']['people_id'], $req['input']['event_version_id']);

        $syncParticipant = new SyncPeopleWithParticipantAction($people, $user);
        $participant = $syncParticipant->execute();

        return $eventVersion->removeParticipant($participant);
    }
}
