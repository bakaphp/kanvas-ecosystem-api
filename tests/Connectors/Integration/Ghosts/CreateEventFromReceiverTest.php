<?php
declare(strict_types= 1);

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
        EventType::create([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'name' => $eventTypeName
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
        dump($result);
    }
}
