<?php

declare(strict_types=1);

namespace Test\Connectors\Integration\Stripe;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Ghost\Jobs\UpdatePeopleGhostSubscription;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class CreatePeopleSubscriptionTest extends TestCase
{
    public function testUpdateSubscription()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->has(Contact::factory()->count(1), 'contacts')
            ->create();

        $payload = [
            'email' => $people->getEmails()[0]->value,
            'created_at' => time(),
            'current_period_start' => time(),
        ];

        $workflowAction = WorkflowAction::firstOrCreate([
            'name' => 'Update People Subscription',
            'model_name' => UpdatePeopleGhostSubscription::class,
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
        $job = new UpdatePeopleGhostSubscription($webhookRequest);
        $result = $job->handle();
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertTrue($result['success']);
    }
}
