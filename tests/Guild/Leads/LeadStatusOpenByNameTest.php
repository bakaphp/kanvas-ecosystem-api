<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Tests\TestCase;

/**
 * Connectors seed statuses by name (Reynolds: Active / Sold / Lost / Closed) and never pass
 * `is_open`, so the flag has to follow the name or the company default, or every newly onboarded
 * tenant's leads read as closed to outreach.
 */
final class LeadStatusOpenByNameTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testAStatusNamedLikeALiveLeadIsOpenOnCreate(): void
    {
        $this->assertTrue($this->makeStatus('Active')->is_open);
        $this->assertTrue($this->makeStatus('OPEN')->is_open);
        $this->assertTrue($this->makeStatus('Lead')->is_open);
        $this->assertTrue($this->makeStatus('new lead')->is_open);
    }

    public function testATerminalOrUnknownNameStaysClosedOnCreate(): void
    {
        $this->assertFalse($this->makeStatus('Closed')->is_open);
        $this->assertFalse($this->makeStatus('Inactive')->is_open);
        $this->assertFalse($this->makeStatus('new lead (dupe)')->is_open);
        $this->assertFalse($this->makeStatus('Sold')->is_open);
    }

    public function testTheCompanyDefaultIsOpenWhateverItsName(): void
    {
        $this->assertTrue($this->makeStatus('Prospect', isDefault: true)->is_open);
    }

    public function testAnExplicitFlagWins(): void
    {
        $status = LeadStatus::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'name' => 'Lost',
            'is_default' => 0,
            'is_open' => true,
        ]);

        $this->assertTrue($status->is_open);
    }

    public function testAConnectorSeededActiveMakesTheLeadOpenEndToEnd(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $active = LeadStatus::firstOrCreate(
            ['apps_id' => $app->getId(), 'companies_id' => $company->getId(), 'name' => 'Active'],
            ['is_default' => 1],
        );
        $closed = $this->makeStatus('Closed');

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create(['leads_status_id' => $active->getId()]);

        $this->assertTrue($lead->isOpen());

        $lead->leads_status_id = $closed->getId();
        $lead->saveOrFail();

        $this->assertFalse($lead->fresh()->isOpen());
    }

    public function testLooksOpenIsExactAndCaseInsensitive(): void
    {
        $this->assertTrue(LeadStatus::looksOpen(' Hot '));
        $this->assertTrue(LeadStatus::looksOpen('ENGAGED'));
        $this->assertFalse(LeadStatus::looksOpen('hot lead'));
        $this->assertFalse(LeadStatus::looksOpen('closed: bad lead'));
        $this->assertTrue(LeadStatus::looksOpen('Anything', isDefault: true));
    }

    private function makeStatus(string $name, bool $isDefault = false): LeadStatus
    {
        return LeadStatus::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => auth()->user()->getCurrentCompany()->getId(),
            'name' => $name,
            'is_default' => $isDefault ? 1 : 0,
        ]);
    }
}
