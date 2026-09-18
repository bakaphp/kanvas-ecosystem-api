<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Reynolds;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\DataTransferObject\Lead as LeadData;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Tests\TestCase;

final class LeadDataProspectTypeTest extends TestCase
{
    public function testInternetLeadTypeIsSentAsInternet(): void
    {
        $lead = $this->leadWithType('internet');

        $this->assertSame('Internet', LeadData::fromLead($lead)->prospectType);
    }

    public function testAnyOtherLeadTypeIsSentAsOther(): void
    {
        $lead = $this->leadWithType('Walk In');

        $prospect = LeadData::fromLead($lead)->toProspect();

        $this->assertSame('Other', $prospect['ProspectType']);
        $this->assertSame('Other', $prospect['ProviderName']);
    }

    public function testLeadWithoutTypeDefaultsToInternet(): void
    {
        $lead = $this->makeLead();

        $this->assertSame('Internet', LeadData::fromLead($lead)->prospectType);
    }

    /**
     * A ProspectType pulled from R&R is already a valid enum value and must
     * survive the round-trip untouched.
     */
    public function testPulledProspectTypeTakesPrecedenceOverLeadType(): void
    {
        $lead = $this->leadWithType('Walk In');
        $lead->set(CustomFieldEnum::PROSPECT_TYPE->value, 'Phone');

        $this->assertSame('Phone', LeadData::fromLead($lead)->prospectType);
    }

    private function leadWithType(string $typeName): Lead
    {
        $lead = $this->makeLead();

        $type = LeadType::firstOrCreate(
            [
                'apps_id' => $lead->apps_id,
                'companies_id' => $lead->companies_id,
                'name' => $typeName,
            ],
            ['is_active' => 1]
        );

        $lead->leads_types_id = $type->getId();
        $lead->saveOrFail();

        return $lead;
    }

    private function makeLead(): Lead
    {
        $user = auth()->user();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create();
    }
}
