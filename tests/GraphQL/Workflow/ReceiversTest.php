<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\TestCase;
use Illuminate\Support\Facades\Request;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class ReceiversTest extends TestCase
{
    /**
     * testCreate.
     *
     * @return void
     */
    public function testGetReceiversHistory(): void
    {
        $this->sendReceiver(
            $this->createReceiver()
        );

        $response = $this->graphQL('
            query {
                receiversHistory {
                    data {
                        id
                        uuid
                        status
                        receiver {
                            id
                            name
                            uuid
                        }
                    }
                }
            }');

            $this->assertArrayHasKey('id', $response->json()['data']['receiversHistory']['data'][0]);
    }

    protected function createReceiver(): ReceiverWebhook
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $receiverWebhook = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create();

        return $receiverWebhook;
    }

    protected function sendReceiver(ReceiverWebhook $receiver): void
    {
        // Define the payload for the HTTP request
        $title = 'New Order';
        $payload = [
            'title' => $title,
            'body' => json_encode(['order_id' => 1]),
        ];

        // Create a new Request instance with the payload
        $request = Request::create('https://localhost/shopifytest', 'POST', $payload);

        // Execute the action and get the webhook request
        $webhookRequest = (new ProcessWebhookAttemptAction($receiver, $request))->execute();

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
