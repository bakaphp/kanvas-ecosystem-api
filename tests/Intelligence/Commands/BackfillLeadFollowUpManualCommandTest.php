<?php

declare(strict_types=1);

namespace Tests\Intelligence\Commands;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Tests\TestCase;

class BackfillLeadFollowUpManualCommandTest extends TestCase
{
    public function testPinsOnlyManualAiModeLeadsWithAStoredFollowUp(): void
    {
        $manualWithFollowUp = $this->createLead(aiModeManual: true, followUp: FollowUpValueEnum::OFF()->value);
        $manualWithoutFollowUp = $this->createLead(aiModeManual: true, followUp: null);
        $automatic = $this->createLead(aiModeManual: false, followUp: FollowUpValueEnum::ON()->value);

        $this->artisan('kanvas:intelligence:backfill-lead-follow-up-manual', $this->arguments())
            ->assertSuccessful();

        $this->assertTrue($this->isPinned($manualWithFollowUp));
        $this->assertFalse($this->isPinned($manualWithoutFollowUp));
        $this->assertFalse($this->isPinned($automatic));
    }

    public function testDryRunWritesNothing(): void
    {
        $lead = $this->createLead(aiModeManual: true, followUp: FollowUpValueEnum::ON()->value);

        $this->artisan('kanvas:intelligence:backfill-lead-follow-up-manual', [...$this->arguments(), '--dry-run' => true])
            ->assertSuccessful();

        $this->assertFalse($this->isPinned($lead));
    }

    private function createLead(bool $aiModeManual, ?int $followUp): Lead
    {
        $lead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();

        $lead->set(ConfigurationEnum::AI_MODE_IS_MANUAL->value, $aiModeManual);
        if ($followUp !== null) {
            $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, $followUp);
        }

        return $lead;
    }

    private function arguments(): array
    {
        return [
            'company_id' => auth()->user()->getCurrentCompany()->getId(),
            'app_id' => app(Apps::class)->getId(),
        ];
    }

    private function isPinned(Lead $lead): bool
    {
        return (bool) Lead::getById($lead->getId())->get(ConfigurationEnum::AI_FOLLOW_UP_IS_MANUAL->value);
    }
}
