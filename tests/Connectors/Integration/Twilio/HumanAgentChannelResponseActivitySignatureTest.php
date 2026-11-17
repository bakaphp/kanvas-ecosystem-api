<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Workflows\HumanAgentChannelResponseActivity;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

/**
 * An email a person writes from the lead inbox closes with that person's own opt-in signature.
 */
class HumanAgentChannelResponseActivitySignatureTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    /** @var array<int, Users> */
    private array $signed = [];

    protected function tearDown(): void
    {
        // Settings are written to Redis as well, which the transaction does not roll back.
        foreach ($this->signed as $user) {
            Redis::del($user->getSettingsRedisPrimaryKey());
        }

        parent::tearDown();
    }

    public function testHumanEmailCarriesTheSendersSignature(): void
    {
        $data = $this->sendHumanEmail(['append' => true, 'text' => "Sales Rep\nInternet Sales"]);

        $this->assertFalse($data['signature']);
        $this->assertStringContainsString(UserEmailSignature::MARKER, $data['user_signature']);
        $this->assertStringContainsString('Sales Rep', $data['user_signature']);
        // The author's signature, not the lead owner's.
        $this->assertStringNotContainsString('Lead Owner', $data['user_signature']);
    }

    public function testHumanEmailWithoutAnEnabledSignatureCarriesNone(): void
    {
        $data = $this->sendHumanEmail(['append' => false, 'text' => 'Sales Rep']);

        $this->assertArrayNotHasKey('user_signature', $data);
    }

    /**
     * @param array<string, mixed> $signature
     * @return array<string, mixed>
     */
    private function sendHumanEmail(array $signature): array
    {
        // CI's SMTP host may be Mailgun's, which files a delivery; that lane has its own suite.
        config(['mail.mailers.smtp.host' => 'smtp.example.com']);
        Notification::fake();

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $owner = Users::factory()->create();
        $this->signed = [$user, $owner];
        // A company signature that is off falls back to the global one, so both are pinned.
        $user->set(UserEmailSignature::companySettingKey($company), $signature);
        $user->set(UserEmailSignature::SETTING, $signature);
        $owner->set(UserEmailSignature::SETTING, ['append' => true, 'text' => 'Lead Owner']);

        $this->setIntegration($app, IntegrationsEnum::INTERNAL, 'internal', $company, $user);

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create(['users_id' => $owner->getId()]);
        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'value' => 'prospect-' . $lead->getId() . '@example.com',
            'weight' => 0,
        ]);

        $channel = new CreateChannelAction(
            ChannelDto::from([
                'apps' => $app,
                'companies' => $company,
                'users' => $lead->user,
                'entity_id' => $lead->getId(),
                'entity_namespace' => Lead::class,
                'name' => 'Human email — ' . $lead->getId(),
                'slug' => 'human-email-' . $lead->getId(),
            ])
        )->execute();

        $create = new CreateMessageAction(new MessageInput(
            app: $app,
            company: $company,
            user: $user,
            type: MessageType::firstOrCreate(
                ['apps_id' => $app->getId(), 'languages_id' => 1, 'verb' => 'email'],
                ['name' => 'Email']
            ),
            message: ['content' => 'Hi from a human', 'from_me' => true, 'from_human' => true],
            is_public: 1,
        ));
        $create->runWorkflow = false;
        $message = $create->execute();

        $message->addEntity($lead);
        $channel->addMessage($message);

        $result = new HumanAgentChannelResponseActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), [])->execute(
            $channel,
            $app,
            ['message' => $message, 'from' => '+15555550100'],
        );

        $this->assertStringContainsString('Message sent to lead via email', $result['message'] ?? json_encode($result));

        $sent = Notification::sent(new AnonymousNotifiable(), Blank::class);
        $this->assertCount(1, $sent);

        return $sent->first()->getData();
    }
}
