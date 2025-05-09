<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\SendLeadEmailsAction;
use Kanvas\Guild\Leads\Actions\SendRotationEmailsAction;
use Kanvas\Guild\Leads\Enums\LeadNotificationModeEnum;
use Kanvas\Guild\Leads\Enums\LeadNotificationUserModeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Guild\Leads\Models\LeadRotationAgent;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Leads\Notifications\NewLeadNotification;
use Kanvas\Guild\LeadSources\Models\LeadSource;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Tests\TestCase;

class SendLeadEmailsTest extends TestCase
{
    public function testSendLeadEmailsFromReceiverConfig(): void
    {
        Notification::fake();
        $user = auth()->user();
        $title = fake()->title();

        $lead = Lead::factory()->create();

        $sendLeadEmailsAction = new SendLeadEmailsAction($lead, 'new-lead');
        $payload = [
            "title" => $title,
            "people" => [
                "contacts" => [
                    ["value" => "jdoe@example.com", "weight" => 0, "contacts_types_id" => 1],
                    ["value" => "82912345678", "weight" => 0, "contacts_types_id" => 2]
                ],
                "lastname" => "Doe",
                "firstname" => "John",
            ],
            "custom_fields" => [
                [
                    "data" => "218062",
                    "name" => "product_id"
                ],
                [
                    "data" => "7",
                    "name" => "share_left"
                ]
            ],
            "pipeline_stage_id" => 0
        ];

        $users = [$user];

        $sendLeadEmailsAction->execute($payload, $users);
        Notification::assertCount(2);
    }

    public function testSendLeadEmailsFromRotationConfig(): void
    {
        Notification::fake();
        $user = auth()->user();
        $title = fake()->title();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $leadRotation = LeadRotation::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Lead Rotation',
            'hits' => 1,
            'leads_rotations_email' => '',
            'config' => [
                'email_template' => 'new-lead',
                'notification_mode' => 'notify_all',
                'notification_user_mode' => 'notify_rotation_users',
            ]
        ]);

        LeadRotationAgent::create([
            'leads_rotations_id' => $leadRotation->id,
            'companies_id' => $company->getId(),
            'users_id' => $user->id,
            'percent' => 100,
        ]);

        $leadType = LeadType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Lead Type',
            'description' => 'Lead Type Description',
            'is_active' => true,
            'uuid' => Str::uuid(),
        ]);

        $leadSource = LeadSource::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Lead Source',
            'description' => 'Lead Source Description',
            'is_active' => true,
            'uuid' => Str::uuid(),
            'leads_types_id' => $leadType->getId(),
        ]);

        $leadReceiver = LeadReceiver::create([
            'name' => fake()->word,
            'agents_id' => $user->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'is_default' => true,
            'rotations_id' => $leadRotation->getId(),
            'source_name' => 'source',
            'lead_types_id' => $leadType->getId(),
            'template' => 'template',
        ]);

        $lead = Lead::factory()->withReceiverId($leadReceiver->getId())->create();

        $sendRotationEmailsAction = new SendRotationEmailsAction($lead, $leadReceiver, $leadRotation, $user);
        $payload = [
            "title" => $title,
            "people" => [
                "contacts" => [
                    ["value" => "jdoe@example.com", "weight" => 0, "contacts_types_id" => 1],
                    ["value" => "8292001222", "weight" => 0, "contacts_types_id" => 2]
                ],
                "lastname" => "Doe",
                "firstname" => "John",
            ],
            "custom_fields" => [
                [
                    "data" => "7",
                    "name" => "share_left"
                ]
            ],
            "pipeline_stage_id" => 0
        ];

        $sendRotationEmailsAction->execute($payload, 'user');
        Notification::assertCount(2);
    }

    public function testSendLeadEmailsInDatabase(): void
    {
        Notification::fake();
        $user = auth()->user();
        $title = fake()->title();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $leadRotation = LeadRotation::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Lead Rotation',
            'hits' => 1,
            'leads_rotations_email' => '',
            'config' => [
                'email_template' => 'new-lead',
                'notification_mode' => LeadNotificationModeEnum::NOTIFY_AGENTS->value,
                'notification_user_mode' => LeadNotificationUserModeEnum::NOTIFY_OWNER,
                'notification_channels' => 'database',
            ]
        ]);

        LeadRotationAgent::create([
            'leads_rotations_id' => $leadRotation->id,
            'companies_id' => $company->getId(),
            'users_id' => $user->id,
            'percent' => 100,
        ]);

        $leadType = LeadType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Lead Type',
            'description' => 'Lead Type Description',
            'is_active' => true,
            'uuid' => Str::uuid(),
        ]);

        $leadSource = LeadSource::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Lead Source',
            'description' => 'Lead Source Description',
            'is_active' => true,
            'uuid' => Str::uuid(),
            'leads_types_id' => $leadType->getId(),
        ]);

        $leadReceiver = LeadReceiver::create([
            'name' => fake()->word,
            'agents_id' => $user->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'is_default' => true,
            'rotations_id' => $leadRotation->getId(),
            'source_name' => 'source',
            'lead_types_id' => $leadType->getId(),
            'template' => 'template',
        ]);

        $lead = Lead::factory()->withReceiverId($leadReceiver->getId())->create();

        $sendRotationEmailsAction = new SendRotationEmailsAction($lead, $leadReceiver, $leadRotation, $user);
        $payload = [
            "title" => $title,
            "people" => [
                "contacts" => [
                    ["value" => "jesusant.guerrero@gmail.com", "weight" => 0, "contacts_types_id" => 1],
                    ["value" => "82912345678", "weight" => 0, "contacts_types_id" => 2]
                ],
                "lastname" => "Guerrero",
                "firstname" => "Jesus",
            ],
            "custom_fields" => [
                [
                    "data" => "7",
                    "name" => "share_left"
                ]
            ],
            "pipeline_stage_id" => 0
        ];

        $sendRotationEmailsAction->execute($payload, 'user');
        Notification::assertCount(1);
        Notification::assertSentTo($user, NewLeadNotification::class, function ($notification, $channels) {
            return in_array(NotificationChannelEnum::getNotificationChannelBySlug('database'), $channels);
        });
    }
}
