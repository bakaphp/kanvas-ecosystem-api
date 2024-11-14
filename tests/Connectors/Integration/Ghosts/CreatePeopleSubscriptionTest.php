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
                "members": [
                    {
                        "id": "668d48281a218a00015d0900",
                        "uuid": "e6267a0a-a10b-4b9f-8d58-a6445cde8144",
                        "email": "jerly@kanvas.dev",
                        "name": "Jerly Rosa",
                        "note": null,
                        "geolocation": null,
                        "subscribed": true,
                        "created_at": "2024-07-09T14:24:40.000Z",
                        "updated_at": "2024-07-09T14:24:40.000Z",
                        "labels": [
                            {
                                "id": "6643c63f7506990001828d62",
                                "name": "company:kanvas",
                                "slug": "company-kanvas",
                                "created_at": "2024-05-14T20:14:55.000Z",
                                "updated_at": "2024-05-14T20:14:55.000Z"
                            },
                            {
                                "id": "6685713ef5713b00017fe4fd",
                                "name": "report:test-3",
                                "slug": "report-test-3",
                                "created_at": "2024-07-03T15:41:50.000Z",
                                "updated_at": "2024-07-03T15:41:50.000Z"
                            },
                            {
                                "id": "6643c63f7506990001828d64",
                                "name": "title:dev",
                                "slug": "title-dev",
                                "created_at": "2024-05-14T20:14:55.000Z",
                                "updated_at": "2024-05-14T20:14:55.000Z"
                            }
                        ],
                        "subscriptions": [],
                        "avatar_image": "",
                        "comped": false,
                        "email_count": 5,
                        "email_opened_count": 0,
                        "email_open_rate": 0,
                        "status": "free",
                        "last_seen_at": null,
                        "attribution": {
                            "id": null,
                            "type": null,
                            "url": null,
                            "title": null,
                            "referrer_source": "Integration: Api",
                            "referrer_medium": "Admin API",
                            "referrer_url": null
                        },
                        "unsubscribe_url": "",
                        "tiers": [],
                        "email_suppression": {
                            "suppressed": false,
                            "info": null
                        },
                        "newsletters": [
                            {
                                "id": "661eb361c96051000859617f",
                                "name": "MC Kanvas",
                                "description": null,
                                "status": "active"
                            },
                            {
                                "id": "6724e7b98b3f59000107e32f",
                                "name": "test",
                                "description": "test",
                                "status": "active"
                            }
                        ]
                    }
                ]
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
