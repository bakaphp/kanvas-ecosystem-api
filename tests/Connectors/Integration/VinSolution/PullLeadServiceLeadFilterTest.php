<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Override;
use Tests\TestCase;

final class PullLeadServiceLeadFilterTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social'];

    private Apps $kanvasApp;
    private Companies $company;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->company->del(ConfigurationEnum::CRM_DOWNLOAD_SERVICE_LEADS->value);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->company->del(ConfigurationEnum::CRM_DOWNLOAD_SERVICE_LEADS->value);

        parent::tearDown();
    }

    public function testAServiceLeadKanvasNeverImportedIsLeftOut(): void
    {
        $this->assertTrue(
            $this->isUnimportedServiceLead($this->leadType('SERVICE')->getId(), $this->newVinLeadId())
        );
    }

    public function testAServiceLeadAlreadyInKanvasKeepsSyncing(): void
    {
        $vinLeadId = $this->newVinLeadId();
        $lead = Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())
            ->create();
        $lead->set(CustomFieldEnum::LEADS->value, $vinLeadId);

        $this->assertFalse(
            $this->isUnimportedServiceLead($this->leadType('SERVICE')->getId(), $vinLeadId)
        );
    }

    public function testASalesLeadIsNeverLeftOut(): void
    {
        $this->assertFalse(
            $this->isUnimportedServiceLead($this->leadType('INTERNET')->getId(), $this->newVinLeadId())
        );
    }

    public function testACompanyThatTurnedTheSettingOnGetsItsServiceLeads(): void
    {
        $this->company->set(ConfigurationEnum::CRM_DOWNLOAD_SERVICE_LEADS->value, 1);

        $this->assertFalse(
            $this->isUnimportedServiceLead($this->leadType('SERVICE')->getId(), $this->newVinLeadId())
        );
    }

    private function isUnimportedServiceLead(int $leadTypeId, int $vinLeadId): bool
    {
        $action = new class ($this->kanvasApp, $this->company, auth()->user()) extends PullLeadAction {
            public function decide(int $leadTypeId, int $vinLeadId): bool
            {
                return $this->isUnimportedServiceLead($leadTypeId, $vinLeadId);
            }
        };

        return $action->decide($leadTypeId, $vinLeadId);
    }

    private function leadType(string $name): LeadType
    {
        return LeadType::firstOrCreate(
            [
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $this->company->getId(),
                'name' => $name,
            ],
            [
                'description' => $name,
                'is_active' => 1,
            ]
        );
    }

    private function newVinLeadId(): int
    {
        return random_int(900000000, 999999999);
    }
}
