<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

final class ClosedLeadFollowUpOffTest extends TestCase
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

    public function testMovingToSoldTurnsTheFollowUpOff(): void
    {
        $lead = $this->createLeadWithFollowUpOn();

        $this->changeStatus($lead, 'Closed Sold');

        $this->assertSame(FollowUpValueEnum::OFF()->value, (int) $lead->get($this->followUpKey($lead)));
    }

    public function testMovingToClosedNotSoldTurnsTheFollowUpOff(): void
    {
        $lead = $this->createLeadWithFollowUpOn();

        $this->changeStatus($lead, 'Closed Not Sold');

        $this->assertSame(FollowUpValueEnum::OFF()->value, (int) $lead->get($this->followUpKey($lead)));
    }

    public function testAiModeIsLeftUntouched(): void
    {
        $lead = $this->createLeadWithFollowUpOn();
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::FULL_ON->value);

        $this->changeStatus($lead, 'Closed Sold');

        $this->assertSame(IntelligenceModeEnum::FULL_ON->value, $lead->get($aiModeKey));
    }

    public function testMovingToAnActiveStatusKeepsTheFollowUp(): void
    {
        $lead = $this->createLeadWithFollowUpOn();

        $this->changeStatus($lead, 'Active');

        $this->assertSame(FollowUpValueEnum::ON()->value, (int) $lead->get($this->followUpKey($lead)));
    }

    public function testAnUpdateWithoutAStatusChangeKeepsTheFollowUp(): void
    {
        $lead = $this->createLeadWithFollowUpOn();
        $this->changeStatus($lead, 'Closed Sold');
        $lead->set($this->followUpKey($lead), FollowUpValueEnum::ON()->value);

        $lead->description = 'Customer came back';
        $lead->saveOrFail();

        $this->assertSame(FollowUpValueEnum::ON()->value, (int) $lead->get($this->followUpKey($lead)));
    }

    private function changeStatus(Lead $lead, string $statusName): void
    {
        $lead->leads_status_id = LeadStatus::create([
            'apps_id' => $lead->apps_id,
            'companies_id' => $lead->companies_id,
            'name' => $statusName,
            'is_default' => 0,
        ])->getId();
        $lead->saveOrFail();
    }

    private function createLeadWithFollowUpOn(): Lead
    {
        $lead = Lead::factory()
            ->withAppAndCompany(app(Apps::class)->getId(), auth()->user()->getCurrentCompany()->getId())
            ->create();
        $lead->set($this->followUpKey($lead), FollowUpValueEnum::ON()->value);

        return $lead;
    }

    private function followUpKey(Lead $lead): string
    {
        return new LeadConfigurationService()->getFollowUpModeKey($lead);
    }
}
