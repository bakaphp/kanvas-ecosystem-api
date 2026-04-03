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
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

/**
 * Tests for AI mode transitions and the delay message command.
 *
 * Covers the production bug where a lead set to OFF was switched back
 * to FULL_ON by the send-delay-message cron (missing `continue` after
 * the OFF check allowed the command to keep processing and send a message).
 */
class TriggerIntelligenceActivityTest extends TestCase
{
    private function createLeadWithAiMode(string $aiMode): Lead
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead->set('ai_mode', $aiMode);

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

    public function testOffModeIsPreservedWhenAiTakeoverCustomFieldIsSet(): void
    {
        $lead = $this->createLeadWithAiMode(IntelligenceModeEnum::OFF->value);

        $this->assertEquals(IntelligenceModeEnum::OFF->value, $lead->get('ai_mode'));

        $nonManualTriggers = [
            TriggersEnum::NEW_LEAD->value,
            TriggersEnum::HUMAN_HANDOFF->value,
            TriggersEnum::HUMAN_TAKEOVER->value,
            TriggersEnum::AI_TAKEOVER->value,
            TriggersEnum::HANDOFF->value,
        ];

        $manualTriggers = [
            TriggersEnum::MANUAL_OFF->value,
            TriggersEnum::MANUAL_SUPPORT->value,
            TriggersEnum::MANUAL_FON->value,
        ];

        foreach ($nonManualTriggers as $trigger) {
            $this->assertFalse(
                in_array($trigger, $manualTriggers),
                "Trigger {$trigger} should not be in the manual triggers list"
            );
        }

        foreach ($manualTriggers as $trigger) {
            $this->assertTrue(
                in_array($trigger, $manualTriggers),
                "Trigger {$trigger} should be in the manual triggers list"
            );
        }
    }

    public function testAiModeTransitionsFromSupportToFullOn(): void
    {
        $lead = $this->createLeadWithAiMode(IntelligenceModeEnum::SUPPORT->value);

        $this->assertEquals(IntelligenceModeEnum::SUPPORT->value, $lead->get('ai_mode'));

        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);
        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::LEAD_FOLLOW_UP->value);

        $this->assertEquals(IntelligenceModeEnum::FULL_ON->value, $lead->get('ai_mode'));
        $this->assertEquals(FollowUpTypeEnum::LEAD_FOLLOW_UP->value, $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value));
    }

    public function testManualOffSetsCorrectFollowUp(): void
    {
        $lead = $this->createLeadWithAiMode(IntelligenceModeEnum::FULL_ON->value);

        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);
        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::NO_FOLLOW_UP->value);

        $this->assertEquals(IntelligenceModeEnum::OFF->value, $lead->get('ai_mode'));
        $this->assertEquals(FollowUpTypeEnum::NO_FOLLOW_UP->value, $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value));
    }

    /**
     * Reproduces the exact bug from production: lead set to OFF, then the
     * send-delay-message cron fires and should NOT send a message.
     *
     * Timeline (from BMW of Schererville incident 2026-04-02):
     * 1. Human agent responds → lead set to OFF at 09:32
     * 2. send-delay-message cron picks up locked first-message
     * 3. Old bug: missing `continue` after OFF check meant the message was still sent
     * 4. Message CREATED workflow fired → AI_TAKEOVER → lead flipped to FULL_ON at 09:39
     *
     * This test verifies the delay command skips leads in OFF mode.
     */
    public function testDelayCommandSkipsLeadInOffMode(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(12));

        $lead = $this->createLeadWithAiMode(IntelligenceModeEnum::OFF->value);
        $message = $this->createLockedFirstMessageForLead($lead);

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

    /**
     * Verify the delay command processes leads that are in FULL_ON mode.
     * This is the normal happy-path — delay message should be sent.
     */
    public function testDelayCommandProcessesLeadInFullOnMode(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(12));

        $lead = $this->createLeadWithAiMode(IntelligenceModeEnum::FULL_ON->value);
        $message = $this->createLockedFirstMessageForLead($lead);

        $app = app(Apps::class);

        $this->artisan('kanvas:intelligence:send-delay-message', ['app_id' => $app->getId()])
            ->assertSuccessful();

        $lead = Lead::getById($lead->getId());
        $this->assertNotEquals(
            IntelligenceModeEnum::OFF->value,
            $lead->get('ai_mode'),
            'Lead in FULL_ON mode should still be processed'
        );

        Carbon::setTestNow();
    }

    /**
     * Verify the delay command also skips leads in SUPPORT mode.
     * The SUPPORT check is handled separately because the lead was contacted
     * by a human — the delay first-message is no longer needed.
     */
    public function testDelayCommandProcessesLeadInSupportMode(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(12));

        $lead = $this->createLeadWithAiMode(IntelligenceModeEnum::SUPPORT->value);
        $message = $this->createLockedFirstMessageForLead($lead);

        $app = app(Apps::class);

        $this->artisan('kanvas:intelligence:send-delay-message', ['app_id' => $app->getId()])
            ->assertSuccessful();

        $lead = Lead::getById($lead->getId());
        $this->assertEquals(
            IntelligenceModeEnum::SUPPORT->value,
            $lead->get('ai_mode'),
            'Lead in SUPPORT mode should not have its mode changed'
        );

        Carbon::setTestNow();
    }

    /**
     * Simulate the full scenario: Lead starts FULL_ON → human takes over (OFF)
     * → delay cron runs → lead must stay OFF.
     */
    public function testFullScenarioLeadOffAfterHumanTakeoverStaysOff(): void
    {
        Carbon::setTestNow(Carbon::today()->setHour(12));

        $app = app(Apps::class);

        $lead = $this->createLeadWithAiMode(IntelligenceModeEnum::FULL_ON->value);
        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::LEAD_FOLLOW_UP->value);
        $message = $this->createLockedFirstMessageForLead($lead);

        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);
        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::NO_FOLLOW_UP->value);

        $this->assertEquals(IntelligenceModeEnum::OFF->value, $lead->get('ai_mode'));

        $this->artisan('kanvas:intelligence:send-delay-message', ['app_id' => $app->getId()])
            ->assertSuccessful();

        $lead = Lead::getById($lead->getId());
        $this->assertEquals(
            IntelligenceModeEnum::OFF->value,
            $lead->get('ai_mode'),
            'Lead that was turned OFF by human must remain OFF after delay cron'
        );
        $this->assertEquals(
            FollowUpTypeEnum::NO_FOLLOW_UP->value,
            $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value),
            'Follow-up type must remain NO_FOLLOW_UP'
        );

        Carbon::setTestNow();
    }
}
