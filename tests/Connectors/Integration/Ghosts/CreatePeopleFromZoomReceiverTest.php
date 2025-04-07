<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Ghosts;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Ghost\Jobs\CreateParticipantFromMeetingZoomJob;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDTO;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class CreatePeopleFromZoomReceiverTest extends TestCase
{
    public function testCreateParticipantFromMeetingZoom(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $eventType = EventType::create([
              'companies_id' => $company->getId(),
              'apps_id' => $app->getId(),
              'users_id' => $user->getId(),
              'name' => fake()->name,
          ]);
        $eventClass = EventClass::create([
            'name' => 'Default',
            'is_default' => 1,
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
        ]);
        $eventCategory = EventCategory::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'event_type_id' => $eventType->getId(),
            'event_class_id' => $eventClass->getId(),
            'name' => 'Default',
            'slug' => 'default',
        ]);
        Theme::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Default',
            'is_default' => 1,
        ]);
        ThemeArea::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Default',
            'is_default' => 1,
        ]);
        ThemeArea::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Virtual',
            'is_default' => 0,
        ]);
        EventStatus::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Default',
            'is_default' => 1,
        ]);
        ParticipantType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Attendee',
        ]);
        $zoomId = fake()->uuid;
        $zoomUrl = "https://us04web.zoom.us/j/{$zoomId}";

        $eventDto = EventDTO::fromMultiple($app, $user, $company, [
            'name' => fake()->name,
            'description' => fake()->text,
            'slug' => fake()->slug,
            'meeting_link' => $zoomUrl,
            'type_id' => $eventType->getId(),
            'category_id' => $eventCategory->getId(),
        ]);
        $event = (new CreateEventAction($eventDto))->execute();
        $this->assertEquals($zoomUrl, $event->meeting_link);
        $workflowAction = WorkflowAction::firstOrCreate([
            'name' => 'Create Participant',
            'model_name' => CreateParticipantFromMeetingZoomJob::class,
        ]);
        $payload = [
            'event' => 'meeting.participant_joined',
            'event_ts' => $zoomId,
            'payload' => [
                'account_id' => 'AAAAAABBBB',
                'object' => [
                    'id' => $zoomId,
                    'uuid' => fake()->uuid,
                    'host_id' => 'x1yCzABCDEfg23HiJKl4mN',
                    'topic' => 'My Meeting',
                    'type' => 8,
                    'start_time' => '2021-07-13T21:44:51Z',
                    'timezone' => 'America/Los_Angeles',
                    'duration' => 60,
                    'participant' => [
                        'user_id' => fake()->uuid,
                        'user_name' => fake()->name,
                        'id' => fake()->uuid(),
                        'participant_uuid' => fake()->uuid(),
                        'date_time' => fake()->dateTime,
                        'email' => fake()->email,
                        'registrant_id' => 'abcdefghij0-klmnopq23456',
                        'participant_user_id' => 'rstuvwxyza789-cde',
                        'customer_key' => '349589LkJyeW',
                        'phone_number' => '8615250064084',
                    ],
                ],
            ],
        ];

        $receiverWebhook = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $workflowAction->getId(),
        ]);

        $request = Request::create('https://localhost/ghosttest', 'POST', $payload);

        $webhookRequest = (new ProcessWebhookAttemptAction($receiverWebhook, $request))->execute();

        Queue::fake();
        $job = (new CreateParticipantFromMeetingZoomJob($webhookRequest));
        $result = $job->handle();
        $this->assertEquals('Participant created', $result['message']);
    }
}
