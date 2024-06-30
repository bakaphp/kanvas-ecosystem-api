<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\TestCase;

final class ProcessWebhookJobTest extends TestCase
{
    public function testHandleSuccess()
    {
        // Set up the necessary data
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Create a ReceiverWebhook instance using a factory
        $receiverWebhook = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create();

        // Define the payload for the HTTP request
        $title = 'New Order';
        $payload = [
            'title' => $title,
            'body' => json_encode(['order_id' => 1]),
        ];

        // Create a new Request instance with the payload
        $request = Request::create('https://localhost/shopifytest', 'POST', $payload);

        // Execute the action and get the webhook request
        $webhookRequest = (new ProcessWebhookAttemptAction($receiverWebhook, $request))->execute();

        // Fake the queue
        Queue::fake();

        // Create a concrete class for the abstract ProcessWebhookJob class
        $job = new class ($webhookRequest) extends ProcessWebhookJob {
            public function execute(): array
            {
                return ['result' => $this->webhookRequest->payload];
            }
        };

        // Call the handle method to process the job
        $result = $job->handle();

        // Assert the payload content
        $this->assertEquals($title, $result['result']['title']);
    }
}
