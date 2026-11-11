<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\TestCase;

final class ZohoLeadDtoAddressTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testThePersonsDefaultAddressWinsOverTheStreetCustomFields(): void
    {
        $lead = $this->leadWithStreetCustomFields();
        $lead->people->addDefaultAddress(Address::fromArray([
            'address' => '742 Evergreen Terrace',
            'city' => 'Springfield',
            'state' => 'IL',
            'zip' => '62704',
            'is_default' => true,
        ]));

        $data = ZohoLead::fromLead($lead)->toArray();

        $this->assertSame('742 Evergreen Terrace', $data['Street']);
        $this->assertSame('Springfield', $data['City']);
        $this->assertSame('IL', $data['State']);
        $this->assertSame('62704', $data['Zip_Code']);
    }

    public function testFallsBackToTheStreetCustomFieldsWhenThePersonHasNoAddress(): void
    {
        $data = ZohoLead::fromLead($this->leadWithStreetCustomFields())->toArray();

        $this->assertSame('1 Infinite Loop', $data['Street']);
        $this->assertSame('Cupertino', $data['City']);
        $this->assertSame('95014', $data['Zip_Code']);
    }

    public function testTheCompanyMapRenamesTheAddressFieldsOnTheWayOut(): void
    {
        $lead = $this->makeLead($this->makeZohoCompany());
        $lead->company->set(CustomFieldEnum::FIELDS_MAP->value, [
            'street' => ['name' => 'Mailing_Street', 'type' => 'string'],
        ]);
        $lead->people->addDefaultAddress(Address::fromArray(['address' => '742 Evergreen Terrace', 'is_default' => true]));

        $data = ZohoLead::fromLead($lead)->toArray();

        $this->assertSame('742 Evergreen Terrace', $data['Mailing_Street']);
        $this->assertArrayNotHasKey('Street', $data);
    }

    private function leadWithStreetCustomFields(): Lead
    {
        $lead = $this->makeLead($this->makeZohoCompany());
        $lead->company->set(CustomFieldEnum::FIELDS_MAP->value, [
            'street' => ['name' => 'Street', 'type' => 'string'],
            'city' => ['name' => 'City', 'type' => 'string'],
            'zip' => ['name' => 'Zip_Code', 'type' => 'string'],
        ]);
        $lead->set('street', '1 Infinite Loop');
        $lead->set('city', 'Cupertino');
        $lead->set('zip', '95014');

        return $lead;
    }
}
