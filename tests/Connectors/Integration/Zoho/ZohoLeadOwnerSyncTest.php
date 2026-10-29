<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\Connectors\Integration\Zoho\Concerns\StubsZohoLeadModule;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;

/**
 * The company's ZOHO_DEFAULT_OWNER is a create-time fallback only. On an update it would re-assign the
 * Zoho lead on every edit while the Kanvas owner (and its ZOHO_USER_OWNER_ID) is right the whole time.
 */
final class ZohoLeadOwnerSyncTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;
    use StubsZohoLeadModule;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testTheDtoCarriesTheOwnersOwnZohoUserNotTheCompanyDefault(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::DEFAULT_OWNER->value, '999');
        $lead = $this->makeOwnedLead($company, '4242');

        $this->assertSame('4242', ZohoLead::fromLead($lead)->toArray()['Owner']);
    }

    public function testTheDtoSendsNoOwnerWhenTheKanvasOwnerHasNoZohoUser(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::DEFAULT_OWNER->value, '999');
        $lead = $this->makeOwnedLead($company);

        $this->assertArrayNotHasKey('Owner', ZohoLead::fromLead($lead)->toArray());
    }

    public function testAnUpdateLeavesTheZohoOwnerAlone(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::DEFAULT_OWNER->value, '999');
        $lead = $this->makeOwnedLead($company);
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-77');

        $module = $this->module();
        $module->method('get')->with('zl-77')->willReturn(new Record(['id' => 'zl-77'], $module));
        $module->expects($this->once())
            ->method('update')
            ->with('zl-77', $this->callback(fn (array $data) => ! array_key_exists('Owner', $data)))
            ->willReturn(new Record(['id' => 'zl-77'], $module));

        $this->action($lead, $module)->execute();
    }

    public function testACreateStillFallsBackToTheCompanyDefaultOwner(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::DEFAULT_OWNER->value, '999');
        $lead = $this->makeOwnedLead($company);

        $module = $this->module();
        $module->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $data) => ($data['Owner'] ?? null) === '999'))
            ->willReturn(new Record(['id' => 'zl-new'], $module));

        $this->action($lead, $module)->execute();

        $this->assertSame('zl-new', $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value));
    }

    private function makeOwnedLead(Companies $company, ?string $ownerZohoId = null): Lead
    {
        $owner = Users::factory()->create();

        if ($ownerZohoId !== null) {
            $owner->set(CustomFieldEnum::ZOHO_USER_OWNER_ID->value, $ownerZohoId);
        }

        $lead = $this->makeLead($company);
        $lead->leads_owner_id = $owner->getId();
        $lead->saveOrFail();

        return $lead->fresh();
    }
}
