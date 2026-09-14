<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SendLeadEmailsTest extends TestCase
{
    use DatabaseTransactions;

    // Rotations are written on `crm`; leaked rows push LeadRotationTest's own off page 1.
    protected $connectionsToTransact = [null, 'crm'];

    public function testSendLeadEmailsFromReceiverConfig(): void
    {
        Notification::fake();
        $user = auth()->user();
        $title = fake()->title();

        $lead = Lead::factory()->create();

        $sendLeadEmailsAction = new SendLeadEmailsAction($lead, 'new-lead');
        $payload = [
            'title' => $title,
            'people' => [
                'contacts' => [
                    ['value' => 'jdoe@example.com', 'weight' => 0, 'contacts_types_id' => 1],
                    ['value' => '82912345678', 'weight' => 0, 'contacts_types_id' => 2],
                ],
                'lastname' => 'Doe',
                'firstname' => 'John',
            ],
            'custom_fields' => [
                [
                    'data' => '218062',
                    'name' => 'product_id',
                ],
                [
                    'data' => '7',
                    'name' => 'share_left',
                ],
            ],
            'pipeline_stage_id' => 0,
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
            'leads_rotations_email' => 'johnd@example.com',
            'config' => [
                'email_template' => 'new-lead',
                'notification_mode' => 'NOTIFY_AGENTS',
                'notification_user_mode' => 'NOTIFY_ROTATION_USERS',
            ],
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
            'title' => $title,
            'people' => [
                'contacts' => [
                    ['value' => 'jdoe@example.com', 'weight' => 0, 'contacts_types_id' => 1],
                    ['value' => '8292001222', 'weight' => 0, 'contacts_types_id' => 2],
                ],
                'lastname' => 'Doe',
                'firstname' => 'John',
            ],
            'custom_fields' => [
                [
                    'data' => '7',
                    'name' => 'share_left',
                ],
            ],
            'pipeline_stage_id' => 0,
        ];

        $sendRotationEmailsAction->execute($payload, 'user');
        Notification::assertCount(3);
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
            ],
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
            'title' => $title,
            'people' => [
                'contacts' => [
                    ['value' => 'jesusant.guerrero@gmail.com', 'weight' => 0, 'contacts_types_id' => 1],
                    ['value' => '82912345678', 'weight' => 0, 'contacts_types_id' => 2],
                ],
                'lastname' => 'Guerrero',
                'firstname' => 'Jesus',
            ],
            'custom_fields' => [
                [
                    'data' => '7',
                    'name' => 'share_left',
                ],
            ],
            'pipeline_stage_id' => 0,
        ];

        $sendRotationEmailsAction->execute($payload, 'user');
        Notification::assertCount(1);
        Notification::assertSentTo($user, NewLeadNotification::class, function ($notification, $channels) {
            return in_array(NotificationChannelEnum::getNotificationChannelBySlug('database'), $channels);
        });
    }

    public static function extraEmailsProvider(): array
    {
        return [
            'receiver address fires even under NOTIFY_OWNER' => [
                LeadNotificationUserModeEnum::NOTIFY_OWNER,
                'ofertas@example.com,contacto@example.com',
                ['ofertas@example.com', 'contacto@example.com'],
            ],
            'receiver address overrides the rotation one' => [
                LeadNotificationUserModeEnum::NOTIFY_ROTATION_USERS,
                'tradein@example.com',
                ['tradein@example.com'],
            ],
            'rotation address is used when the receiver has none' => [
                LeadNotificationUserModeEnum::NOTIFY_ROTATION_USERS,
                null,
                ['rotation@example.com'],
            ],
            'blank receiver address falls back to the rotation' => [
                LeadNotificationUserModeEnum::NOTIFY_ROTATION_USERS,
                ' , ',
                ['rotation@example.com'],
            ],
        ];
    }

    #[DataProvider('extraEmailsProvider')]
    public function testExtraEmailsResolution(
        LeadNotificationUserModeEnum $userMode,
        ?string $receiverEmail,
        array $expectedEmails
    ): void {
        Notification::fake();

        $leadRotation = $this->makeRotation('rotation@example.com', $userMode);
        $leadReceiver = $this->makeReceiver($leadRotation, $receiverEmail);
        $lead = Lead::factory()->withReceiverId($leadReceiver->getId())->create();

        new SendRotationEmailsAction(
            $lead,
            $leadReceiver,
            $leadRotation,
            auth()->user()
        )->execute($this->leadPayload(), 'user');

        Notification::assertSentOnDemandTimes(NewLeadNotification::class, count($expectedEmails));

        foreach ($expectedEmails as $email) {
            Notification::assertSentOnDemand(
                NewLeadNotification::class,
                fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $email
            );
        }
    }

    private function makeRotation(string $rotationEmail, LeadNotificationUserModeEnum $userMode): LeadRotation
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $leadRotation = LeadRotation::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Lead Rotation',
            'hits' => 1,
            'leads_rotations_email' => $rotationEmail,
            'config' => [
                'email_template' => 'new-lead',
                'notification_mode' => LeadNotificationModeEnum::NOTIFY_AGENTS->value,
                'notification_user_mode' => $userMode->value,
            ],
        ]);

        LeadRotationAgent::create([
            'leads_rotations_id' => $leadRotation->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'percent' => 100,
        ]);

        return $leadRotation;
    }

    private function makeReceiver(LeadRotation $leadRotation, ?string $notificationEmail = null): LeadReceiver
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $leadType = LeadType::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => 'Lead Type',
            'description' => 'Lead Type Description',
            'is_active' => true,
            'uuid' => Str::uuid(),
        ]);

        return LeadReceiver::create([
            'name' => fake()->word,
            'agents_id' => $user->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'users_id' => $user->getId(),
            'is_default' => true,
            'rotations_id' => $leadRotation->getId(),
            'source_name' => 'source',
            'notification_email' => $notificationEmail,
            'lead_types_id' => $leadType->getId(),
            'template' => 'template',
        ]);
    }

    private function leadPayload(): array
    {
        return [
            'title' => fake()->title(),
            'people' => [
                'contacts' => [
                    ['value' => 'jdoe@example.com', 'weight' => 0, 'contacts_types_id' => 1],
                    ['value' => '8292001222', 'weight' => 0, 'contacts_types_id' => 2],
                ],
                'lastname' => 'Doe',
                'firstname' => 'John',
            ],
            'custom_fields' => [
                [
                    'data' => '7',
                    'name' => 'share_left',
                ],
            ],
            'pipeline_stage_id' => 0,
        ];
    }
}
