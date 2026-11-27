<?php

declare(strict_types=1);

namespace Tests\Intelligence\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Enums\LeadMessageTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Triggers\Actions\ApplyAiModeAction;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

final class ApplyAiModeActionNoteTest extends TestCase
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

        $result = new ApplyAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

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

        $result = new ApplyAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertSame(IntelligenceModeEnum::IDLE->value, $result['mods_current']['ai_mode']);
        $this->assertSame(FollowUpValueEnum::OFF()->value, (int) $result['mods_current']['ai_follow_up']);
        $this->assertSame(FollowUpValueEnum::OFF()->value, (int) $lead->get(LeadConfigurationEnum::HAS_FOLLOW_UP->value));
        $this->assertTrue(
            $lead->systemNotes()->firstOrFail()->messages()->get()
                ->contains(fn ($message): bool => $message->message['content'] === 'Follow-up disabled'),
            'Turning the follow-up off must be noted on the lead default channel.'
        );
    }

    public function testAModeSetOnTheActiveLeadIsReplicatedOnItsPerson(): void
    {
        $lead = $this->createLead();

        new ApplyAiModeAction($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertSame(IntelligenceModeEnum::IDLE, $lead->people->getAiMode());
        $this->assertTrue((bool) $lead->people->get(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value));
        $this->assertTrue($lead->people->isAiMuted());
    }

    public function testAModeSetOnThePersonLandsOnTheirActiveLead(): void
    {
        $lead = $this->createLead();

        $result = new ApplyAiModeAction($lead->people, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertTrue($result['changed']);
        $this->assertTrue($lead->refresh()->isAiMuted());
        $this->assertSame(IntelligenceModeEnum::IDLE, $lead->people->getAiMode());
        $this->assertSame(
            FollowUpValueEnum::OFF()->value,
            (int) $lead->get(new LeadConfigurationService()->getFollowUpModeKey($lead))
        );
    }

    public function testAnOlderLeadDoesNotSpeakForThePersonOnceANewerOneIsActive(): void
    {
        $older = $this->createLead();
        $newer = $this->createLead($older->people);

        new ApplyAiModeAction($older, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertTrue($older->isAiMuted());
        $this->assertNull($older->people->getAiMode());
        $this->assertFalse((bool) $older->people->get(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value));
        $this->assertFalse($older->people->isAiMuted(), 'the active lead is still on');

        new ApplyAiModeAction($newer, TriggersEnum::MANUAL_SUPPORT->value)->execute();

        $this->assertSame(IntelligenceModeEnum::SUPPORT, $newer->people->getAiMode());
    }

    public function testAPinLeftByAnOlderLeadDoesNotSilenceThePersonOnANewLead(): void
    {
        $older = $this->createLead();
        new ApplyAiModeAction($older, TriggersEnum::MANUAL_OFF->value)->execute();
        $person = $older->people;
        $this->assertTrue($person->isAiMuted());

        $older->softDelete();
        $newer = $this->createLead($person);
        $newer->set(new LeadConfigurationService()->getAiModeKey($newer), IntelligenceModeEnum::FULL_ON->value);
        new ApplyAiModeAction($newer, TriggersEnum::AI_TAKEOVER->value)->execute();

        $this->assertFalse((bool) $person->get(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value));
        $this->assertSame(IntelligenceModeEnum::FULL_ON, $person->getAiMode());
        $this->assertFalse($person->isAiMuted());
    }

    public function testAnUnpinnedModeOnThePersonSilencesNothingByItself(): void
    {
        $lead = $this->createLead();
        $lead->people->set(new LeadConfigurationService()->getAiModeKey($lead), IntelligenceModeEnum::IDLE->value);

        $this->assertFalse($lead->people->isAiMuted());
    }

    public function testAPersonWithNoLeadKeepsAModeSetByHand(): void
    {
        $person = People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();

        $ignored = new ApplyAiModeAction($person, TriggersEnum::NEW_LEAD->value)->execute();
        $result = new ApplyAiModeAction($person, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertFalse($ignored['changed']);
        $this->assertTrue($result['changed']);
        $this->assertSame(IntelligenceModeEnum::IDLE->value, $result['mods_current']['ai_mode']);
        $this->assertTrue($person->isAiMuted());
    }

    private function createLead(?People $person = null): Lead
    {
        $factory = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId());

        return ($person !== null ? $factory->withPeopleId($person->getId()) : $factory)->create();
    }
}
