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
            'model_name' => UpdatePeopleGhostSubscriptionJob::class,
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
        $job = new UpdatePeopleGhostSubscriptionJob($webhookRequest);
        $result = $job->handle();
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertTrue($result['success']);
    }

    public function testCreatePeopleSubscription()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $workflowAction = WorkflowAction::firstOrCreate([
            'name' => 'Create People',
            'model_name' => CreatePeopleFromGhostReceiverJob::class,
        ]);

        $jsonPayload = '{
            "member": {
                "current": {
                    "id": "673743cb53556b0001bae263",
                    "name": "no confio",
                    "note": null,
                    "uuid": "5138e4ef-769b-467e-9009-4306dbddb351",
                    "email": "noconfio@kanvas.com",
                    "tiers": [],
                    "comped": false,
                    "labels": [
                        {
                            "id": "664f65409be91c00019a623c",
                            "name": "company:dev",
                            "slug": "company-dev",
                            "created_at": "2024-05-23T15:48:16.000Z",
                            "updated_at": "2024-05-23T15:48:16.000Z"
                        },
                        {
                            "id": "66465a4258da4100010e46b7",
                            "name": "title:front",
                            "slug": "title-front",
                            "created_at": "2024-05-16T19:10:58.000Z",
                            "updated_at": "2024-05-16T19:10:58.000Z"
                        }
                    ],
                    "status": "free",
                    "created_at": "2024-11-15T12:51:23.000Z",
                    "subscribed": true,
                    "updated_at": "2024-11-15T12:51:23.000Z",
                    "email_count": 0,
                    "geolocation": null,
                    "newsletters": [
                        {
                            "id": "661eb361c96051000859617f",
                            "name": "MC Kanvas",
                            "status": "active",
                            "description": null
                        },
                        {
                            "id": "6724e7b98b3f59000107e32f",
                            "name": "test",
                            "status": "active",
                            "description": "test"
                        }
                    ],
                    "avatar_image": "https://www.gravatar.com/avatar/3cbfd2bc0b654942a7622f199678e5a9?s=250&r=g&d=blank",
                    "last_seen_at": null,
                    "subscriptions": [],
                    "email_open_rate": null,
                    "email_opened_count": 0
                },
                "previous": []
            }
        }';

        $payload = json_decode($jsonPayload, true);

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
        $job = new CreatePeopleFromGhostReceiverJob($webhookRequest);
        $result = $job->handle();

        $this->assertArrayHasKey('people', $result);
        $this->assertInstanceOf(People::class, People::getById($result['people']));
    }
}
