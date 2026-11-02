<?php

declare(strict_types=1);

namespace Tests\Intelligence\Workflows;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Workflows\TurnOffClosedLeadFollowUpActivity;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class TurnOffClosedLeadFollowUpActivityTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    protected function setUp(): void
    {
        parent::setUp();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $user = auth()->user();
        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::INTERNAL,
            'Kanvas\\Connectors\\Internal\\Handlers\\InternalHandler',
            $user->getCurrentCompany(),
            $user
        );
    }

    public function testSoldLeadFollowUpIsTurnedOff(): void
    {
        $lead = $this->createLeadWithFollowUpOn('Closed Sold');

        $result = $this->runActivity($lead);

        $this->assertSame(FollowUpValueEnum::OFF()->value, (int) $result['mods_current']['ai_follow_up']);
        $this->assertSame(FollowUpValueEnum::OFF()->value, (int) $lead->get($this->followUpKey($lead)));
    }

    public function testClosedNotSoldLeadFollowUpIsTurnedOff(): void
    {
        $lead = $this->createLeadWithFollowUpOn('Closed Not Sold');

        $result = $this->runActivity($lead);

        $this->assertSame(FollowUpValueEnum::OFF()->value, (int) $result['mods_current']['ai_follow_up']);
        $this->assertSame(FollowUpValueEnum::OFF()->value, (int) $lead->get($this->followUpKey($lead)));
    }

    public function testAiModeIsLeftUntouched(): void
    {
        $lead = $this->createLeadWithFollowUpOn('Closed Sold');
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::FULL_ON->value);

        $this->runActivity($lead);

        $this->assertSame(IntelligenceModeEnum::FULL_ON->value, $lead->get($aiModeKey));
    }

    public function testActiveLeadIsSkipped(): void
    {
        $lead = $this->createLeadWithFollowUpOn('Active');

        $result = $this->runActivity($lead);

        $this->assertSame('Skipped: lead is not closed sold or closed not sold', $result['message']);
        $this->assertSame(FollowUpValueEnum::ON()->value, (int) $lead->get($this->followUpKey($lead)));
    }

    private function runActivity(Lead $lead): array
    {
        return new TurnOffClosedLeadFollowUpActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute($lead, app(Apps::class), []);
    }

    private function createLeadWithFollowUpOn(string $statusName): Lead
    {
        $appId = app(Apps::class)->getId();
        $companyId = auth()->user()->getCurrentCompany()->getId();

        $status = LeadStatus::create([
            'apps_id' => $appId,
            'companies_id' => $companyId,
            'name' => $statusName,
            'is_default' => 0,
        ]);

        $lead = Lead::factory()->withAppAndCompany($appId, $companyId)->create();
        $lead->leads_status_id = $status->getId();
        $lead->saveOrFail();
        $lead->set($this->followUpKey($lead), FollowUpValueEnum::ON()->value);

        return $lead;
    }

    private function followUpKey(Lead $lead): string
    {
        return new LeadConfigurationService()->getFollowUpModeKey($lead);
    }
}
