<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

class ReceiversTest extends TestCase
{
    public function testGetReceiversHistory(): void
    {
        $this->sendReceiver(
            $this->createReceiver()
        );

        $response = $this->graphQL('
            query {
                workflowReceiverHistory {
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

        $this->assertArrayHasKey('id', $response->json()['data']['workflowReceiverHistory']['data'][0]);
    }

    public function testCreateReceiverWebhook(): void
    {
        $action = WorkflowAction::first();

        $input = [
            'action_id' => $action->getId(),
            'name' => 'Test Receiver ' . fake()->word(),
            'description' => 'Test description',
            'is_active' => true,
            'run_async' => true,
        ];

        $this->graphQL('
            mutation($input: ReceiverWebhookInput!) {
                createReceiverWebhook(input: $input) {
                    id
                    name
                    description
                    url
                    is_active
                    run_async
                    action {
                        id
                        name
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createReceiverWebhook' => [
                    'name' => $input['name'],
                    'description' => $input['description'],
                    'is_active' => true,
                    'run_async' => true,
                ],
            ],
        ]);
    }

    public function testUpdateReceiverWebhook(): void
    {
        $action = WorkflowAction::first();

        $input = [
            'action_id' => $action->getId(),
            'name' => 'Test Receiver ' . fake()->word(),
            'description' => 'Test description',
        ];

        $createResponse = $this->graphQL('
            mutation($input: ReceiverWebhookInput!) {
                createReceiverWebhook(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $id = $createResponse->json('data.createReceiverWebhook.id');

        $updateInput = [
            'name' => 'Updated Receiver ' . fake()->word(),
            'description' => 'Updated description',
            'is_active' => false,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateReceiverWebhookInput!) {
                updateReceiverWebhook(id: $id, input: $input) {
                    id
                    name
                    description
                    is_active
                }
            }
        ', ['id' => $id, 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateReceiverWebhook' => [
                    'name' => $updateInput['name'],
                    'description' => $updateInput['description'],
                    'is_active' => false,
                ],
            ],
        ]);
    }

    public function testDeleteReceiverWebhook(): void
    {
        $action = WorkflowAction::first();

        $input = [
            'action_id' => $action->getId(),
            'name' => 'Test Receiver ' . fake()->word(),
        ];

        $createResponse = $this->graphQL('
            mutation($input: ReceiverWebhookInput!) {
                createReceiverWebhook(input: $input) {
                    id
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $id = $createResponse->json('data.createReceiverWebhook.id');

        $this->graphQL('
            mutation($id: ID!) {
                deleteReceiverWebhook(id: $id)
            }
        ', ['id' => $id])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteReceiverWebhook' => true]]);
    }

    public function testCannotDeleteReceiverWebhookInUse(): void
    {
        $receiver = $this->createReceiver();
        $this->sendReceiver($receiver);

        $this->graphQL('
            mutation($id: ID!) {
                deleteReceiverWebhook(id: $id)
            }
        ', ['id' => $receiver->getId()])
        ->assertJson([
            'errors' => [
                [
                    'message' => 'Cannot delete a receiver webhook that has been used. Use force delete with cascade to remove it along with its call history',
                ],
            ],
        ]);
    }

    public function testListReceiverWebhooks(): void
    {
        $this->createReceiver();

        $this->graphQL('
            query {
                receiverWebhooks {
                    data {
                        id
                        uuid
                        name
                        description
                        url
                        is_active
                        run_async
                        action {
                            id
                            name
                        }
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'receiverWebhooks' => [
                    'data' => [
                        ['id', 'uuid', 'name', 'url', 'is_active', 'action'],
                    ],
                ],
            ],
        ]);
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
