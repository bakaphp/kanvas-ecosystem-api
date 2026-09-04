<?php

declare(strict_types=1);

namespace Tests\Intelligence\Commands;

use App\Console\Commands\Intelligence\Messaging\SendDelayMessageCommand;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadAiModeAction;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class TriggerIntelligenceActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    private function createLead(string $leadTypeName = ''): Lead
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        if ($leadTypeName !== '') {
            $leadType = LeadType::firstOrCreate(
                [
                    'apps_id' => $app->getId(),
                    'companies_id' => $company->getId(),
                    'name' => $leadTypeName,
                ],
                ['description' => $leadTypeName . ' Lead', 'is_active' => 1]
            );

            $lead->leads_types_id = $leadType->getId();
            $lead->saveOrFail();
        }

        return $lead;
    }

    private function setLeadTypeConfig(Lead $lead, array $config): void
    {
        $leadType = LeadType::find($lead->leads_types_id);
        if ($leadType) {
            $leadType->config = $config;
            $leadType->saveOrFail();
        }
    }

    private function createLockedFirstMessageForLead(Lead $lead): Message
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $company->set(CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value, 15);

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads']
        );

        $messageType = MessageType::firstOrCreate([
            'apps_id' => $app->getId(),
            'languages_id' => 1,
            'verb' => 'twilio-sms',
        ], ['name' => 'Twilio SMS']);

        $message = new Message();
        $message->apps_id = $app->getId();
        $message->companies_id = $company->getId();
        $message->users_id = $user->getId();
        $message->message_types_id = $messageType->getId();
        $message->message = ['content' => 'Customer inquiry message'];
        $message->is_locked = 1;
        $message->is_public = 0;
        $message->created_at = now()->subMinutes(30);
        $message->updated_at = now()->subMinutes(30);
        $message->save();

        DB::connection('social')->table('app_module_message')->insert([
            'message_id' => $message->id,
            'message_types_id' => $messageType->getId(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules' => Lead::class,
            'entity_id' => $lead->getId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $message->set('communicationChannel', 'sms');
        $message->set('from_number', '+1234567890');
        $message->set('title', 'Customer Message');

        $message->addTag('first-message', $app, $user, $company);

        $lead->set(LeadsEnumsConfigurationEnum::FIRST_MESSAGE->value, 'Follow-up message content');

        return $message;
    }

    public function testOffModeBlocksAiTakeover(): void
    {
        $lead = $this->createLead('Internet');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::IDLE->value);

        $result = new ApplyLeadAiModeAction($lead, TriggersEnum::AI_TAKEOVER->value)->execute();

        $this->assertFalse($result['changed']);
        $this->assertEquals(IntelligenceModeEnum::IDLE->value, $lead->get($aiModeKey));
    }

    public function testOffModeBlocksNewLeadTrigger(): void
    {
        $lead = $this->createLead('Internet');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::IDLE->value);

        $result = new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertFalse($result['changed']);
        $this->assertEquals(IntelligenceModeEnum::IDLE->value, $lead->get($aiModeKey));
    }

    public function testOffModeBlocksAllNonManualTriggers(): void
    {
        $nonManualTriggers = [
            TriggersEnum::NEW_LEAD,
            TriggersEnum::HUMAN_HANDOFF,
            TriggersEnum::HUMAN_TAKEOVER,
            TriggersEnum::AI_TAKEOVER,
            TriggersEnum::SOLD_LEAD,
            TriggersEnum::CLOSE_LEAD,
            TriggersEnum::HANDOFF,
        ];

        foreach ($nonManualTriggers as $trigger) {
            $lead = $this->createLead('Internet');
            $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
            $lead->set($aiModeKey, IntelligenceModeEnum::IDLE->value);

            $result = new ApplyLeadAiModeAction($lead, $trigger->value)->execute();

            $this->assertFalse($result['changed'], "Trigger {$trigger->name} should NOT override OFF mode");
            $this->assertEquals(
                IntelligenceModeEnum::IDLE->value,
                $lead->get($aiModeKey),
                "Lead must stay OFF after {$trigger->name} trigger"
            );
        }
    }

    public function testManualFonCanOverrideOffMode(): void
    {
        $lead = $this->createLead('Internet');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::IDLE->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_FON->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::FULL_ON->value, $lead->get($aiModeKey));
    }

    public function testManualSupportCanOverrideOffMode(): void
    {
        $lead = $this->createLead('Internet');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::IDLE->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_SUPPORT->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::SUPPORT->value, $lead->get($aiModeKey));
    }

    public function testInternetLeadManualOffSetsAiModeKey(): void
    {
        $lead = $this->createLead('Internet');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::FULL_ON->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::IDLE->value, $lead->get($aiModeKey));
    }

    public function testInternetLeadFollowUpOnSetsFollowUpKey(): void
    {
        $lead = $this->createLead('Internet');
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_ON->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get($followUpKey));
    }

    public function testInternetLeadFollowUpOffSetsFollowUpKey(): void
    {
        $lead = $this->createLead('Internet');
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);
        $lead->set($followUpKey, FollowUpValueEnum::ON()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_OFF->value)->execute();

        $this->assertEquals(FollowUpValueEnum::OFF()->value, $lead->get($followUpKey));
    }

    public function testShowroomLeadManualOffSetsAiModeKey(): void
    {
        $lead = $this->createLead('Showroom');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::FULL_ON->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::IDLE->value, $lead->get($aiModeKey));
    }

    public function testShowroomLeadFollowUpOnSetsFollowUpKey(): void
    {
        $lead = $this->createLead('Showroom');
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_ON->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get($followUpKey));
    }

    public function testShowroomLeadFollowUpOffSetsFollowUpKey(): void
    {
        $lead = $this->createLead('Showroom');
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);
        $lead->set($followUpKey, FollowUpValueEnum::ON()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_OFF->value)->execute();

        $this->assertEquals(FollowUpValueEnum::OFF()->value, $lead->get($followUpKey));
    }

    public function testPhoneLeadManualOffSetsAiModeKey(): void
    {
        $lead = $this->createLead('Phone');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::FULL_ON->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::IDLE->value, $lead->get($aiModeKey));
    }

    public function testPhoneLeadFollowUpOnSetsFollowUpKey(): void
    {
        $lead = $this->createLead('Phone');
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_ON->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get($followUpKey));
    }

    public function testPhoneLeadFollowUpOffSetsFollowUpKey(): void
    {
        $lead = $this->createLead('Phone');
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);
        $lead->set($followUpKey, FollowUpValueEnum::ON()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_OFF->value)->execute();

        $this->assertEquals(FollowUpValueEnum::OFF()->value, $lead->get($followUpKey));
    }

    public function testEachLeadTypeUsesItsOwnFollowUpKey(): void
    {
        $leadTypeNames = ['Internet', 'Showroom', 'Phone'];

        foreach ($leadTypeNames as $typeName) {
            $lead = $this->createLead($typeName);
            $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);

            new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_ON->value)->execute();

            $this->assertEquals(
                FollowUpValueEnum::ON()->value,
                $lead->get($followUpKey),
                "{$typeName} lead should write to {$followUpKey}"
            );

            new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_OFF->value)->execute();

            $this->assertEquals(
                FollowUpValueEnum::OFF()->value,
                $lead->get($followUpKey),
                "{$typeName} lead should clear {$followUpKey}"
            );
        }
    }

    public function testDelayCommandSkipsLeadInOffMode(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(12));

        $lead = $this->createLead('Internet');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::IDLE->value);
        $this->createLockedFirstMessageForLead($lead);

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $companiesWithConfig = Companies::getByCustomFieldBuilder(
            CompanyConfigurationEnum::MESSAGE_MINUTES_INTERVAL->value,
            null
        )->get();
        $this->assertTrue(
            $companiesWithConfig->contains('id', $company->getId()),
            'Test company must be found by the command query'
        );

        $this->artisan('kanvas:intelligence:send-delay-message', ['app_id' => $app->getId()])
            ->assertSuccessful();

        $lead = Lead::getById($lead->getId());
        $this->assertEquals(
            IntelligenceModeEnum::IDLE->value,
            $lead->get($aiModeKey),
            'Lead in OFF mode must NOT be switched by the delay command'
        );

        Carbon::setTestNow();
    }

    public function testNewLeadInternetUsesLeadTypeAiModeDefault(): void
    {
        $lead = $this->createLead('Internet');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $aiModeDefaultKey = new LeadConfigurationService()->getAiModeDefaultKey($lead);

        $this->setLeadTypeConfig($lead, [$aiModeDefaultKey => IntelligenceModeEnum::FULL_ON->value]);
        $lead->company->set($aiModeKey, IntelligenceModeEnum::SUPPORT->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::FULL_ON->value, $lead->get($aiModeKey));
    }

    public function testNewLeadShowroomUsesLeadTypeAiModeDefault(): void
    {
        $lead = $this->createLead('Showroom');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $aiModeDefaultKey = new LeadConfigurationService()->getAiModeDefaultKey($lead);

        $this->setLeadTypeConfig($lead, [$aiModeDefaultKey => IntelligenceModeEnum::SUPPORT->value]);
        $lead->company->set($aiModeKey, IntelligenceModeEnum::FULL_ON->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::SUPPORT->value, $lead->get($aiModeKey));
    }

    public function testNewLeadPhoneUsesLeadTypeAiModeDefault(): void
    {
        $lead = $this->createLead('Phone');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $aiModeDefaultKey = new LeadConfigurationService()->getAiModeDefaultKey($lead);

        $this->setLeadTypeConfig($lead, [$aiModeDefaultKey => IntelligenceModeEnum::FULL_ON->value]);
        $lead->company->set($aiModeKey, IntelligenceModeEnum::SUPPORT->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::FULL_ON->value, $lead->get($aiModeKey));
    }

    public function testNewLeadInternetUsesLeadTypeFollowUpDefault(): void
    {
        $lead = $this->createLead('Internet');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);
        $followUpDefaultKey = new LeadConfigurationService()->getFollowUpDefaultKey($lead);

        $this->setLeadTypeConfig($lead, [$followUpDefaultKey => FollowUpValueEnum::ON()->value]);
        $lead->company->set($aiModeKey, IntelligenceModeEnum::FULL_ON->value);
        $lead->company->set($followUpKey, FollowUpValueEnum::OFF()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get($followUpKey));
    }

    public function testNewLeadShowroomUsesLeadTypeFollowUpDefault(): void
    {
        $lead = $this->createLead('Showroom');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);
        $followUpDefaultKey = new LeadConfigurationService()->getFollowUpDefaultKey($lead);

        $this->setLeadTypeConfig($lead, [$followUpDefaultKey => FollowUpValueEnum::ON()->value]);
        $lead->company->set($aiModeKey, IntelligenceModeEnum::FULL_ON->value);
        $lead->company->set($followUpKey, FollowUpValueEnum::OFF()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get($followUpKey));
    }

    public function testNewLeadPhoneUsesLeadTypeFollowUpDefault(): void
    {
        $lead = $this->createLead('Phone');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);
        $followUpDefaultKey = new LeadConfigurationService()->getFollowUpDefaultKey($lead);

        $this->setLeadTypeConfig($lead, [$followUpDefaultKey => FollowUpValueEnum::ON()->value]);
        $lead->company->set($aiModeKey, IntelligenceModeEnum::FULL_ON->value);
        $lead->company->set($followUpKey, FollowUpValueEnum::OFF()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get($followUpKey));
    }

    public function testNewLeadFallsBackToCompanyConfigWhenLeadTypeConfigNotSet(): void
    {
        $lead = $this->createLead('Internet');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);

        $lead->company->set($aiModeKey, IntelligenceModeEnum::SUPPORT->value);
        $lead->company->set($followUpKey, FollowUpValueEnum::ON()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::SUPPORT->value, $lead->get($aiModeKey));
        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get($followUpKey));
    }

    public function testFullScenarioLeadOffAfterManualOffStaysOff(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(12));

        $app = app(Apps::class);
        $lead = $this->createLead('Internet');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::FULL_ON->value);
        $this->createLockedFirstMessageForLead($lead);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();
        $this->assertEquals(IntelligenceModeEnum::IDLE->value, $lead->get($aiModeKey));

        $this->artisan('kanvas:intelligence:send-delay-message', ['app_id' => $app->getId()])
            ->assertSuccessful();

        $lead = Lead::getById($lead->getId());
        $this->assertEquals(
            IntelligenceModeEnum::IDLE->value,
            $lead->get($aiModeKey),
            'Lead that was turned OFF must remain OFF after delay cron'
        );

        $result = new ApplyLeadAiModeAction($lead, TriggersEnum::AI_TAKEOVER->value)->execute();
        $this->assertFalse($result['changed']);
        $this->assertEquals(
            IntelligenceModeEnum::IDLE->value,
            $lead->get($aiModeKey),
            'AI_TAKEOVER must NOT override OFF mode'
        );

        Carbon::setTestNow();
    }

    public function testDelayCommandUsesPersistedAgentReachOutContent(): void
    {
        $lead = $this->createLead('Internet');
        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
        $message = $this->createLockedFirstMessageForLead($lead);
        $message->message = array_merge($message->message, ['content' => 'New agent reach-out content']);
        $message->saveOrFail();
        $message->addTag('agent-reach-out', $lead->app, $lead->user, $lead->company);
        $message->addTag('agent-reach-out-delayed', $lead->app, $lead->user, $lead->company);

        $command = new TestableSendDelayMessageCommand();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $command->processForTest($lead->company, $message, 15);

        $this->assertSame('New agent reach-out content', $command->sentContent);
    }

    public function testDelayCommandDoesNotReleaseAgentReachOutApprovalDraft(): void
    {
        $lead = $this->createLead('Internet');
        $message = $this->createLockedFirstMessageForLead($lead);
        $message->addTag('agent-reach-out', $lead->app, $lead->user, $lead->company);

        $command = new TestableSendDelayMessageCommand();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $command->processForTest($lead->company, $message, 15);

        $this->assertNull($command->sentContent);
        $this->assertTrue($message->fresh()->isLocked());
    }
}

final class TestableSendDelayMessageCommand extends SendDelayMessageCommand
{
    public ?string $sentContent = null;

    public function processForTest(Companies $company, Message $message, int $delayMinutes): void
    {
        $this->processMessage($company, $message, $delayMinutes);
    }

    protected function sendCrmDelayNote(
        Lead $lead,
        Message $message,
        Companies $company,
        int $delayMinutes
    ): bool {
        return true;
    }

    protected function sendDelayedMessage(Lead $lead, Message $message, string $messageContent): void
    {
        $this->sentContent = $messageContent;
    }
}
