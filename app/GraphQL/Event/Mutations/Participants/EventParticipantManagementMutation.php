<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Participants;

use App\GraphQL\Concerns\SyncsEntityRelatedInput;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Actions\SyncPeopleWithParticipantAction;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Guild\Customers\Models\People;

class EventParticipantManagementMutation
{
    use SyncsEntityRelatedInput;

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

        foreach (['ticket_price', 'discount', 'invoice_date', 'payment_status'] as $key) {
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

        // custom_fields / files / tags apply to the Participant record
        self::syncEntityRelatedInput($participant, $input);

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

    /**
     * Copy all active participants from one EventVersion to another.
     * Existing participants on the target are left alone; if the target already has
     * the same (participant_id, participant_type_id), the row is restored instead of duplicated.
     *
     * @return int count of rows copied or restored
     */
    public function copyParticipantsToEventVersion(mixed $root, array $req): int
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $req['input'];

        /** @var EventVersion $from */
        $from = EventVersion::getByIdFromCompanyApp((int) $input['from_event_version_id'], $company, $app);
        /** @var EventVersion $to */
        $to = EventVersion::getByIdFromCompanyApp((int) $input['to_event_version_id'], $company, $app);

        return DB::connection('event')->transaction(function () use ($from, $to) {
            $sourceRows = EventVersionParticipant::where('event_version_id', $from->getId())
                ->where('is_deleted', 0)
                ->get();

            $count = 0;
            foreach ($sourceRows as $source) {
                $existing = EventVersionParticipant::withTrashed()
                    ->where('event_version_id', $to->getId())
                    ->where('participant_id', $source->participant_id)
                    ->where('participant_type_id', $source->participant_type_id)
                    ->first();

                if ($existing) {
                    if ($existing->is_deleted) {
                        $existing->restore();
                        $existing->is_deleted = 0;
                        $existing->saveOrFail();
                        $count++;
                    }

                    continue;
                }

                $copy = new EventVersionParticipant();
                $copy->event_version_id = $to->getId();
                $copy->participant_id = $source->participant_id;
                $copy->participant_type_id = $source->participant_type_id;
                $copy->ticket_price = $source->ticket_price;
                $copy->discount = $source->discount;
                $copy->invoice_date = $source->invoice_date;
                $copy->metadata = $source->metadata;
                $copy->is_deleted = 0;
                $copy->saveOrFail();
                $count++;
            }

            return $count;
        });
    }
}
