<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Ghosts;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Ghost\Jobs\CreatePeopleFromGhostReceiverJob;
use Kanvas\Connectors\Ghost\Jobs\UpdatePeopleGhostSubscriptionJob;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;
use Kanvas\Connectors\Ghost\Jobs\CreateEventFromGhostReceiverJob;
use Kanvas\Event\Events\DataTransferObject\Event;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Connectors\Ghost\Enums\CustomFieldEnum;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Event\Events\Models\EventStatus;

final class CreateEventFromReceiverTest extends TestCase
{
    public function testCreateEventFromWebhook(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $payload = [
            "posts" => [
                [
                    "primary_tag" => [
                        "slug" => fake()->slug,
                        "name" => fake()->name,
                        'is_report' => true
                    ]
                ]
            ]
        ];
        $workflowAction = WorkflowAction::firstOrCreate([
            'name' => 'Create People',
            'model_name' => CreatePeopleFromGhostReceiverJob::class,
        ]);
        $eventTypeName = fake()->name;
        $app->set(CustomFieldEnum::WEBHOOK_IS_REPORT_EVENT->value, $eventTypeName);
        $eventType = EventType::create([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'name' => $eventTypeName
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
        EventStatus::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Default',
            'is_default' => 1,
        ]);
        $receiverWebhook = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $workflowAction->getId(),
        ]);
        $request = Request::create('https://localhost/ghosttest', 'POST', $payload);

        // Execute the action and get the webhook request
        $webhookRequest = (new ProcessWebhookAttemptAction($receiverWebhook, $request))->execute();

        // Fake the queue
        Queue::fake();
        $job = new CreateEventFromGhostReceiverJob($webhookRequest);
        $result = $job->handle();
        $this->assertEquals($payload["posts"][0]["primary_tag"]["name"], $result['name']);
    }
}
