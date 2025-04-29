<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ghost\Jobs;

use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Participants\Actions\SyncPeopleWithParticipantAction;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class CreateParticipantFromMeetingZoomJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload['payload'];
        $zoomId = $payload['object']['id'];
        $event = Event::whereLike('meeting_link', "%https://us04web.zoom.us/j/{$zoomId}%")
                ->first();

        if (! $event) {
            return [
                'message' => 'No data found',
                'payload' => $this->webhookRequest->payload,
            ];
        }
        $company = $event->company;
        $people = PeoplesRepository::getByEmail($payload['object']['participant']['email'], $company);
        if (! $people) {
            $peopleDto = People::from([
                'app' => $this->webhookRequest->receiverWebhook->app,
                'company' => $company,
                'user' => $this->webhookRequest->receiverWebhook->user,
                'firstname' => $payload['object']['participant']['user_name'],
                'contacts' => [
                    [
                        'value' => $payload['object']['participant']['email'],
                        'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                        'weight' => 0,
                    ],
                ],
                'address' => [],
                'branch' => $company->defaultBranch
            ]);
            $action = new CreatePeopleAction($peopleDto);
            $people = $action->execute();
        }
        $sync = new SyncPeopleWithParticipantAction($people, $this->webhookRequest->receiverWebhook->user);
        $participant = $sync->execute();
        $eventVersion = $event->versions()->first();
        $eventVersion->addParticipant($participant);

        return [
            'message' => 'Participant created',
            'people' => $people->toArray(),
            'participant' => $participant->toArray(),
        ];
    }
}
