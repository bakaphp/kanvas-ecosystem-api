<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoDeal;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Users\Models\Users;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\TestCase;

final class ZohoDealDtoTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testMapsStandardFieldsOwnerLeadLinkAndTheCompanyFieldMap(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::ZOHO_DEAL_FIELDS_MAP->value, [
            'business_legal_name' => 'Business_Name',
            'funding_amount_requested' => ['name' => 'Amount_Requested', 'type' => 'currency'],
            'zip' => ['name' => 'Zip_Code', 'type' => 'string'],
        ]);

        $owner = Users::factory()->create();
        $owner->set(CustomFieldEnum::ZOHO_USER_OWNER_ID->value, '4242');

        $lead = $this->makeLead($company);
        $lead->set(CustomFieldEnum::ZOHO_LEAD_ID->value, 'zl-1');

        $deal = $this->makeDeal(
            $company,
            $owner,
            $this->makeStage($company, 'Underwriting'),
            $lead
        );
        $deal->set('business_legal_name', 'Acme LLC');
        $deal->set('funding_amount_requested', '$50,000');
        $deal->set('zip', '33033');

        $data = ZohoDeal::fromDeal($deal)->toArray();

        $this->assertSame($deal->title, $data['Deal_Name']);
        $this->assertSame('Pushed from a test', $data['Description']);
        $this->assertSame('Underwriting', $data['Stage']);
        $this->assertSame('4242', $data['Owner']);
        $this->assertSame('zl-1', $data['ZCRM_Lead_ID']);
        $this->assertSame('Acme LLC', $data['Business_Name']);
        $this->assertSame(50000.0, $data['Amount_Requested']);
        $this->assertSame('33033', $data['Zip_Code']);
        $this->assertArrayNotHasKey('zip', $data, 'the Kanvas key must never reach Zoho as a field name');
        $this->assertArrayNotHasKey('additionalFields', $data);
    }

    public function testStageOverrideAndCompanyDefaultOwnerWinWhenSet(): void
    {
        $company = $this->makeZohoCompany();
        $company->set(CustomFieldEnum::DEFAULT_OWNER->value, '999');

        $stage = $this->makeStage($company, 'Underwriting');
        $stage->set(CustomFieldEnum::ZOHO_STATUS_NAME->value, 'Needs Analysis');

        $data = ZohoDeal::fromDeal($this->makeDeal($company, stage: $stage))->toArray();

        $this->assertSame('Needs Analysis', $data['Stage']);
        $this->assertSame('999', $data['Owner']);
    }

    public function testFallsBackToZohosFirstStageAndDropsWhatItCannotResolve(): void
    {
        $company = $this->makeZohoCompany();

        $data = ZohoDeal::fromDeal($this->makeDeal($company))->toArray();

        $this->assertSame(ZohoDeal::DEFAULT_STAGE, $data['Stage']);
        $this->assertArrayNotHasKey('Owner', $data, 'an unresolved owner is dropped so Zoho assigns its API user');
        $this->assertArrayNotHasKey('ZCRM_Lead_ID', $data);
    }

    public function testADealWhoseLeadIsNotInZohoIsStillPushedWithoutTheLink(): void
    {
        $company = $this->makeZohoCompany();
        $lead = $this->makeLead($company);

        $data = ZohoDeal::fromDeal($this->makeDeal($company, lead: $lead))->toArray();

        $this->assertArrayNotHasKey('ZCRM_Lead_ID', $data);
        $this->assertArrayHasKey('Deal_Name', $data);
    }
}
