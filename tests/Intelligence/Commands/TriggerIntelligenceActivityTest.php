<?php

declare(strict_types=1);

namespace Tests\Intelligence\Commands;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadsEnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadAiModeAction;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

class TriggerIntelligenceActivityTest extends TestCase
{
    private function createLead(string $leadTypeName = '', array $leadTypeConfig = []): Lead
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

            if (! empty($leadTypeConfig)) {
                $leadType->config = $leadTypeConfig;
                $leadType->saveOrFail();
            }

            $lead->leads_types_id = $leadType->getId();
            $lead->saveOrFail();
        }

        return $lead;
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
        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);

        $result = new ApplyLeadAiModeAction($lead, TriggersEnum::AI_TAKEOVER->value)->execute();

        $this->assertFalse($result['changed']);
        $this->assertEquals(IntelligenceModeEnum::OFF->value, $lead->get('ai_mode'));
    }

    public function testOffModeBlocksNewLeadTrigger(): void
    {
        $lead = $this->createLead('Internet');
        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);

        $result = new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertFalse($result['changed']);
        $this->assertEquals(IntelligenceModeEnum::OFF->value, $lead->get('ai_mode'));
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
            $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);

            $result = new ApplyLeadAiModeAction($lead, $trigger->value)->execute();

            $this->assertFalse($result['changed'], "Trigger {$trigger->name} should NOT override OFF mode");
            $this->assertEquals(
                IntelligenceModeEnum::OFF->value,
                $lead->get('ai_mode'),
                "Lead must stay OFF after {$trigger->name} trigger"
            );
        }
    }

    public function testManualFonCanOverrideOffMode(): void
    {
        $lead = $this->createLead('Internet');
        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_FON->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::FULL_ON->value, $lead->get('ai_mode'));
    }

    public function testManualSupportCanOverrideOffMode(): void
    {
        $lead = $this->createLead('Internet');
        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_SUPPORT->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::SUPPORT->value, $lead->get('ai_mode'));
    }

    public function testInternetLeadManualOffSetsAiModeKey(): void
    {
        $lead = $this->createLead('Internet');
        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::OFF->value, $lead->get('ai_mode'));
    }

    public function testInternetLeadFollowUpOnSetsInternetFollowUpKey(): void
    {
        $lead = $this->createLead('Internet');

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_ON->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get('internet_follow_up_mode'));
        $this->assertNull($lead->get('showroom_follow_up_mode'));
        $this->assertNull($lead->get('phone_follow_up_mode'));
    }

    public function testInternetLeadFollowUpOffSetsInternetFollowUpKey(): void
    {
        $lead = $this->createLead('Internet');
        $lead->set('internet_follow_up_mode', FollowUpValueEnum::ON()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_OFF->value)->execute();

        $this->assertEquals(FollowUpValueEnum::OFF()->value, $lead->get('internet_follow_up_mode'));
    }

    public function testShowroomLeadManualOffSetsShowroomAiModeKey(): void
    {
        $lead = $this->createLead('Showroom');
        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::OFF->value, $lead->get('showroom_ai_mode'));
    }

    public function testShowroomLeadFollowUpOnSetsShowroomFollowUpKey(): void
    {
        $lead = $this->createLead('Showroom');

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_ON->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get('showroom_follow_up_mode'));
        $this->assertNull($lead->get('internet_follow_up_mode'));
        $this->assertNull($lead->get('phone_follow_up_mode'));
    }

    public function testShowroomLeadFollowUpOffSetsShowroomFollowUpKey(): void
    {
        $lead = $this->createLead('Showroom');
        $lead->set('showroom_follow_up_mode', FollowUpValueEnum::ON()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_OFF->value)->execute();

        $this->assertEquals(FollowUpValueEnum::OFF()->value, $lead->get('showroom_follow_up_mode'));
    }

    public function testPhoneLeadManualOffSetsPhoneAiModeKey(): void
    {
        $lead = $this->createLead('Phone');
        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::OFF->value, $lead->get('phone_ai_mode'));
    }

    public function testPhoneLeadFollowUpOnSetsPhoneFollowUpKey(): void
    {
        $lead = $this->createLead('Phone');

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_ON->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get('phone_follow_up_mode'));
        $this->assertNull($lead->get('internet_follow_up_mode'));
        $this->assertNull($lead->get('showroom_follow_up_mode'));
    }

    public function testPhoneLeadFollowUpOffSetsPhoneFollowUpKey(): void
    {
        $lead = $this->createLead('Phone');
        $lead->set('phone_follow_up_mode', FollowUpValueEnum::ON()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_OFF->value)->execute();

        $this->assertEquals(FollowUpValueEnum::OFF()->value, $lead->get('phone_follow_up_mode'));
    }

    public function testEachLeadTypeUsesItsOwnFollowUpKey(): void
    {
        $cases = [
            'Internet' => 'internet_follow_up_mode',
            'Showroom' => 'showroom_follow_up_mode',
            'Phone'    => 'phone_follow_up_mode',
        ];

        foreach ($cases as $typeName => $expectedKey) {
            $lead = $this->createLead($typeName);

            new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_ON->value)->execute();

            $this->assertEquals(
                FollowUpValueEnum::ON()->value,
                $lead->get($expectedKey),
                "{$typeName} lead should write to {$expectedKey}"
            );

            new ApplyLeadAiModeAction($lead, TriggersEnum::FOLLOW_UP_OFF->value)->execute();

            $this->assertEquals(
                FollowUpValueEnum::OFF()->value,
                $lead->get($expectedKey),
                "{$typeName} lead should clear {$expectedKey}"
            );
        }
    }

    public function testDelayCommandSkipsLeadInOffMode(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(12));

        $lead = $this->createLead('Internet');
        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);
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
            IntelligenceModeEnum::OFF->value,
            $lead->get('ai_mode'),
            'Lead in OFF mode must NOT be switched by the delay command'
        );

        Carbon::setTestNow();
    }

    public function testNewLeadInternetUsesLeadTypeAiModeDefault(): void
    {
        $lead = $this->createLead('Internet', ['internet_ai_mode_default' => IntelligenceModeEnum::FULL_ON->value]);
        $lead->company->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::FULL_ON->value, $lead->get('ai_mode'));
    }

    public function testNewLeadShowroomUsesLeadTypeAiModeDefault(): void
    {
        $lead = $this->createLead('Showroom', ['showroom_ai_mode_default' => IntelligenceModeEnum::SUPPORT->value]);
        $lead->company->set('showroom_ai_mode', IntelligenceModeEnum::FULL_ON->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::SUPPORT->value, $lead->get('showroom_ai_mode'));
    }

    public function testNewLeadPhoneUsesLeadTypeAiModeDefault(): void
    {
        $lead = $this->createLead('Phone', ['phone_ai_mode_default' => IntelligenceModeEnum::FULL_ON->value]);
        $lead->company->set('phone_ai_mode', IntelligenceModeEnum::SUPPORT->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::FULL_ON->value, $lead->get('phone_ai_mode'));
    }

    public function testNewLeadInternetUsesLeadTypeFollowUpDefault(): void
    {
        $lead = $this->createLead('Internet', ['internet_followup_default_mode' => FollowUpValueEnum::ON()->value]);
        $lead->company->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);
        $lead->company->set('internet_follow_up_mode', FollowUpValueEnum::OFF()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get('internet_follow_up_mode'));
    }

    public function testNewLeadShowroomUsesLeadTypeFollowUpDefault(): void
    {
        $lead = $this->createLead('Showroom', ['showroom_followup_default_mode' => FollowUpValueEnum::ON()->value]);
        $lead->company->set('showroom_ai_mode', IntelligenceModeEnum::FULL_ON->value);
        $lead->company->set('showroom_follow_up_mode', FollowUpValueEnum::OFF()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get('showroom_follow_up_mode'));
    }

    public function testNewLeadPhoneUsesLeadTypeFollowUpDefault(): void
    {
        $lead = $this->createLead('Phone', ['phone_followup_default_mode' => FollowUpValueEnum::ON()->value]);
        $lead->company->set('phone_ai_mode', IntelligenceModeEnum::FULL_ON->value);
        $lead->company->set('phone_follow_up_mode', FollowUpValueEnum::OFF()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get('phone_follow_up_mode'));
    }

    public function testNewLeadFallsBackToCompanyConfigWhenLeadTypeConfigNotSet(): void
    {
        $lead = $this->createLead('Internet');
        $lead->company->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
        $lead->company->set('internet_follow_up_mode', FollowUpValueEnum::ON()->value);

        new ApplyLeadAiModeAction($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::SUPPORT->value, $lead->get('ai_mode'));
        $this->assertEquals(FollowUpValueEnum::ON()->value, $lead->get('internet_follow_up_mode'));
    }

    public function testFullScenarioLeadOffAfterManualOffStaysOff(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(12));

        $app = app(Apps::class);
        $lead = $this->createLead('Internet');
        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);
        $this->createLockedFirstMessageForLead($lead);

        new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();
        $this->assertEquals(IntelligenceModeEnum::OFF->value, $lead->get('ai_mode'));

        $this->artisan('kanvas:intelligence:send-delay-message', ['app_id' => $app->getId()])
            ->assertSuccessful();

        $lead = Lead::getById($lead->getId());
        $this->assertEquals(
            IntelligenceModeEnum::OFF->value,
            $lead->get('ai_mode'),
            'Lead that was turned OFF must remain OFF after delay cron'
        );

        $result = new ApplyLeadAiModeAction($lead, TriggersEnum::AI_TAKEOVER->value)->execute();
        $this->assertFalse($result['changed']);
        $this->assertEquals(
            IntelligenceModeEnum::OFF->value,
            $lead->get('ai_mode'),
            'AI_TAKEOVER must NOT override OFF mode'
        );

        Carbon::setTestNow();
    }
}
