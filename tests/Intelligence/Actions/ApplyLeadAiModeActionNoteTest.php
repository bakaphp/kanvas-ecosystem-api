<?php

declare(strict_types=1);

namespace Tests\Intelligence\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadAiModeAction;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

final class ApplyLeadAiModeActionNoteTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    protected function setUp(): void
    {
        parent::setUp();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );
    }

    public function testAModeChangeIsNotedOnTheDefaultChannelAsTheLeadOwner(): void
    {
        $lead = $this->createLead();

        $result = new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertTrue($result['changed']);
        $note = $lead->systemNotes()->firstOrFail()->messages()->get()
            ->first(fn ($message): bool => $message->hasTag([LeadMessageTypeEnum::AI_CONTROL->value]));
        $this->assertNotNull($note, 'The mode change must be noted on the lead default channel.');
        $this->assertStringEndsWith('Mode set to IDLE', $note->message['content']);
        $this->assertSame(LeadMessageTypeEnum::AI_CONTROL->value, $note->messageType->verb);
        $this->assertSame($lead->users_id, $note->users_id);
    }

    public function testManualOffAlsoTurnsTheFollowUpOff(): void
    {
        $lead = $this->createLead();
        $followUpKey = new LeadConfigurationService()->getFollowUpModeKey($lead);
        $lead->set($followUpKey, FollowUpValueEnum::ON()->value);

        $result = new ApplyLeadAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertSame(IntelligenceModeEnum::IDLE->value, $result['mods_current']['ai_mode']);
        $this->assertSame(FollowUpValueEnum::OFF()->value, (int) $result['mods_current']['ai_follow_up']);
        $this->assertSame(FollowUpValueEnum::OFF()->value, (int) $lead->get(LeadConfigurationEnum::HAS_FOLLOW_UP->value));
        $this->assertTrue(
            $lead->systemNotes()->firstOrFail()->messages()->get()
                ->contains(fn ($message): bool => $message->message['content'] === 'Follow-up disabled'),
            'Turning the follow-up off must be noted on the lead default channel.'
        );
    }

    private function createLead(): Lead
    {
        return Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();
    }
}
