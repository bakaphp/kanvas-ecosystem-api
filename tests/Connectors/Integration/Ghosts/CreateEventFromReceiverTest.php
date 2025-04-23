<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Ghosts;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Ghost\Enums\CustomFieldEventWebhookEnum;
use Kanvas\Connectors\Ghost\Jobs\CreateEventFromGhostReceiverJob;
use Kanvas\Connectors\Ghost\Jobs\CreatePeopleFromGhostReceiverJob;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

final class CreateEventFromReceiverTest extends TestCase
{
    public function testCreateEventFromWebhook(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $eventTypeName = fake()->name;
        $payload = [
            'post' => [
                'current' => [
                    'title'       => fake()->name,
                    'slug'        => fake()->slug,
                    'primary_tag' => [
                        'slug'      => fake()->slug,
                        'name'      => 'is_report',
                        'is_report' => true,
                    ],
                    'published_at' => fake()->dateTime->format('Y-m-d H:i:s'),
                    'tags'         => [
                        [
                            'name'             => fake()->name,
                            'slug'             => fake()->slug,
                            'description'      => null,
                            'meta_description' => null,
                            'meta_title'       => null,
                            'url'              => fake()->url,
                            'visibility'       => 'public',
                        ],
                        [
                            'name'             => fake()->name,
                            'slug'             => fake()->slug,
                            'description'      => null,
                            'meta_description' => null,
                            'meta_title'       => null,
                            'url'              => fake()->url,
                            'visibility'       => 'public',
                        ],
                        [
                            'name'             => fake()->name,
                            'slug'             => fake()->slug,
                            'description'      => null,
                            'meta_description' => null,
                            'meta_title'       => null,
                            'url'              => fake()->url,
                            'visibility'       => 'public',
                        ],
                    ],
                ],
            ],
        ];
        $workflowAction = WorkflowAction::firstOrCreate([
            'name'       => 'Create People',
            'model_name' => CreatePeopleFromGhostReceiverJob::class,
        ]);

        $app->set(CustomFieldEventWebhookEnum::WEBHOOK_IS_REPORT_EVENT->value, $eventTypeName);
        $eventType = EventType::create([
            'companies_id' => $company->getId(),
            'apps_id'      => $app->getId(),
            'users_id'     => $user->getId(),
            'name'         => $eventTypeName,
        ]);
        $eventClass = EventClass::create([
            'name'         => 'Default',
            'is_default'   => 1,
            'companies_id' => $company->getId(),
            'apps_id'      => $app->getId(),
            'users_id'     => $user->getId(),
        ]);
        $eventCategory = EventCategory::create([
            'apps_id'        => $app->getId(),
            'companies_id'   => $company->getId(),
            'users_id'       => $user->getId(),
            'event_type_id'  => $eventType->getId(),
            'event_class_id' => $eventClass->getId(),
            'name'           => 'Default',
            'slug'           => 'default',
        ]);
        Theme::create([
            'apps_id'      => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id'     => $user->getId(),
            'name'         => 'Default',
            'is_default'   => 1,
        ]);
        ThemeArea::create([
            'apps_id'      => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id'     => $user->getId(),
            'name'         => 'Default',
            'is_default'   => 1,
        ]);
        EventStatus::create([
            'apps_id'      => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id'     => $user->getId(),
            'name'         => 'Default',
            'is_default'   => 1,
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
        $this->assertEquals($payload['post']['current']['title'], $result['name']);
    }

    public function testCreateWebForumEventFromWebhook(): void
    {
        $app = app(Apps::class);

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $eventTypeName = fake()->name;
        $eventType = EventType::create([
            'companies_id' => $company->getId(),
            'apps_id'      => $app->getId(),
            'users_id'     => $user->getId(),
            'name'         => $eventTypeName,
        ]);

        $eventClass = EventClass::create([
            'name'         => 'Default',
            'is_default'   => 1,
            'companies_id' => $company->getId(),
            'apps_id'      => $app->getId(),
            'users_id'     => $user->getId(),
        ]);
        $eventCategory = EventCategory::create([
            'apps_id'        => $app->getId(),
            'companies_id'   => $company->getId(),
            'users_id'       => $user->getId(),
            'event_type_id'  => $eventType->getId(),
            'event_class_id' => $eventClass->getId(),
            'name'           => 'Default',
            'slug'           => 'default',
        ]);
        Theme::create([
            'apps_id'      => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id'     => $user->getId(),
            'name'         => 'Default',
            'is_default'   => 1,
        ]);
        ThemeArea::create([
            'apps_id'      => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id'     => $user->getId(),
            'name'         => 'Default',
            'is_default'   => 1,
        ]);
        EventStatus::create([
            'apps_id'      => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id'     => $user->getId(),
            'name'         => 'Default',
            'is_default'   => 1,
        ]);
        $payload = [
            'post' => [
                'current' => [
                    'title'       => fake()->name,
                    'slug'        => fake()->slug,
                    'primary_tag' => [
                        'slug'      => fake()->slug,
                        'name'      => 'web-forum',
                        'is_report' => true,
                    ],
                    'published_at' => fake()->dateTime->format('Y-m-d H:i:s'),
                    'tags'         => [
                        [
                            'name'             => fake()->name,
                            'slug'             => fake()->slug,
                            'description'      => null,
                            'meta_description' => null,
                            'meta_title'       => null,
                            'url'              => fake()->url,
                            'visibility'       => 'public',
                        ],
                        [
                            'name'             => fake()->name,
                            'slug'             => fake()->slug,
                            'description'      => null,
                            'meta_description' => null,
                            'meta_title'       => null,
                            'url'              => fake()->url,
                            'visibility'       => 'public',
                        ],
                        [
                            'name'             => fake()->name,
                            'slug'             => fake()->slug,
                            'description'      => null,
                            'meta_description' => null,
                            'meta_title'       => null,
                            'url'              => fake()->url,
                            'visibility'       => 'public',
                        ],
                    ],
                ],
            ],
        ];

        $app->set(CustomFieldEventWebhookEnum::WEBHOOK_WEB_FORUM_EVENT->value, $eventTypeName);

        $workflowAction = WorkflowAction::firstOrCreate([
            'name'       => 'Create People',
            'model_name' => CreatePeopleFromGhostReceiverJob::class,
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
        $this->assertEquals($payload['post']['current']['title'], $result['name']);
        $this->assertEquals($payload['post']['current']['tags'][2]['name'], $result['meeting_link']);
    }
}
