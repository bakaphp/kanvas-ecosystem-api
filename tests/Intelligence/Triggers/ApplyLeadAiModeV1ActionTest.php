<?php

declare(strict_types=1);

namespace Tests\Intelligence\Triggers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadAiModeV1Action;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Tests\TestCase;

class ApplyLeadAiModeV1ActionTest extends TestCase
{
    private function createLead(string $leadTypeName = 'Internet'): Lead
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $lead = Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

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

        return $lead;
    }

    public function testOffModeBlocksNonManualTriggers(): void
    {
        $lead = $this->createLead();
        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);

        $nonManualTriggers = [
            TriggersEnum::NEW_LEAD,
            TriggersEnum::AI_TAKEOVER,
            TriggersEnum::HUMAN_HANDOFF,
            TriggersEnum::HUMAN_TAKEOVER,
            TriggersEnum::HANDOFF,
            TriggersEnum::SOLD_LEAD,
            TriggersEnum::CLOSE_LEAD,
        ];

        foreach ($nonManualTriggers as $trigger) {
            $result = new ApplyLeadAiModeV1Action($lead, $trigger->value)->execute();

            $this->assertFalse($result['changed'], "Trigger {$trigger->name} should be blocked in V1 OFF mode");
            $this->assertEquals(
                IntelligenceModeEnum::OFF->value,
                $lead->get('ai_mode'),
                "ai_mode must stay OFF after {$trigger->name}"
            );
        }
    }

    public function testManualFonOverridesOff(): void
    {
        $lead = $this->createLead();
        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);

        new ApplyLeadAiModeV1Action($lead, TriggersEnum::MANUAL_FON->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::FULL_ON->value, $lead->get('ai_mode'));
    }

    public function testManualSupportOverridesOff(): void
    {
        $lead = $this->createLead();
        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);

        new ApplyLeadAiModeV1Action($lead, TriggersEnum::MANUAL_SUPPORT->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::SUPPORT->value, $lead->get('ai_mode'));
    }

    public function testManualOffSetsOffMode(): void
    {
        $lead = $this->createLead();
        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

        new ApplyLeadAiModeV1Action($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::OFF->value, $lead->get('ai_mode'));
    }

    public function testManualOffSetsNoFollowUp(): void
    {
        $lead = $this->createLead();
        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

        new ApplyLeadAiModeV1Action($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertEquals(
            FollowUpTypeEnum::NO_FOLLOW_UP->value,
            $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value)
        );
    }

    public function testNewLeadSetsAiModeFromCompanyConfig(): void
    {
        $lead = $this->createLead();
        $lead->company->set(CompanyConfigurationEnum::AI_MODE->value, IntelligenceModeEnum::SUPPORT->value);

        new ApplyLeadAiModeV1Action($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::SUPPORT->value, $lead->get('ai_mode'));
    }

    public function testNewLeadFallsBackToFullOnWhenNoCompanyConfig(): void
    {
        $lead = $this->createLead();
        $lead->company->set(CompanyConfigurationEnum::AI_MODE->value, null);

        new ApplyLeadAiModeV1Action($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(IntelligenceModeEnum::FULL_ON->value, $lead->get('ai_mode'));
    }

    public function testNewLeadSetsLeadFollowUpType(): void
    {
        $lead = $this->createLead();

        new ApplyLeadAiModeV1Action($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertEquals(
            FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
            $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value)
        );
    }

    public function testManualFonSetsLeadFollowUpForNonSoldLead(): void
    {
        $lead = $this->createLead();

        new ApplyLeadAiModeV1Action($lead, TriggersEnum::MANUAL_FON->value)->execute();

        $this->assertEquals(
            FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
            $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value)
        );
    }

    public function testChangedFlagIsTrueWhenModeChanges(): void
    {
        $lead = $this->createLead();
        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

        $result = new ApplyLeadAiModeV1Action($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertTrue($result['changed']);
    }

    public function testChangedFlagIsFalseWhenModeDoesNotChange(): void
    {
        $lead = $this->createLead();
        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);

        $result = new ApplyLeadAiModeV1Action($lead, TriggersEnum::NEW_LEAD->value)->execute();

        $this->assertFalse($result['changed']);
    }

    public function testResultContainsModsPreviousAndModsCurrent(): void
    {
        $lead = $this->createLead();
        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

        $result = new ApplyLeadAiModeV1Action($lead, TriggersEnum::MANUAL_OFF->value)->execute();

        $this->assertArrayHasKey('mods_previous', $result);
        $this->assertArrayHasKey('mods_current', $result);
        $this->assertEquals(IntelligenceModeEnum::FULL_ON->value, $result['mods_previous']['ai_mode']);
        $this->assertEquals(IntelligenceModeEnum::OFF->value, $result['mods_current']['ai_mode']);
    }

    public function testV1UsesGenericAiModeKeyRegardlessOfLeadType(): void
    {
        $leadTypes = ['Internet', 'Showroom', 'Phone'];

        foreach ($leadTypes as $typeName) {
            $lead = $this->createLead($typeName);
            $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

            new ApplyLeadAiModeV1Action($lead, TriggersEnum::MANUAL_OFF->value)->execute();

            $this->assertEquals(
                IntelligenceModeEnum::OFF->value,
                $lead->get('ai_mode'),
                "V1 must always use generic 'ai_mode' key for {$typeName} lead"
            );

            $this->assertNull(
                $lead->get('showroom_ai_mode'),
                "V1 must NOT write type-specific keys for {$typeName} lead"
            );
            $this->assertNull(
                $lead->get('phone_ai_mode'),
                "V1 must NOT write type-specific keys for {$typeName} lead"
            );
        }
    }
}
