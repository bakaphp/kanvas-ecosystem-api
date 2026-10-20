<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Actions\SyncZohoLeadAction;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadReceiverAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadReceiver as LeadReceiverData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Support\Setup;
use Mockery;
use Tests\TestCase;
use Webleit\ZohoCrmApi\Models\Record;
use Webleit\ZohoCrmApi\Modules\Module;

final class SyncZohoLeadPersonDataTest extends TestCase
{
    private const array FIELDS_MAP = [
        'street' => ['name' => 'Street', 'type' => 'string'],
        'city' => ['name' => 'City', 'type' => 'string'],
        'state' => ['name' => 'State', 'type' => 'string'],
        'zip' => ['name' => 'Zip_Code', 'type' => 'string'],
        'Date_of_Birth' => ['name' => 'Date_of_Birth', 'type' => 'string'],
    ];

    public function testNewLeadStoresAddressAndDobOnPersonAndLead(): void
    {
        $zohoLeadId = 'zoho-' . uniqid();
        $lead = $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '742 Evergreen Terrace'));

        $this->assertInstanceOf(Lead::class, $lead);
        $this->assertSame('1985-04-12', $lead->people->dob->format('Y-m-d'));

        $address = $lead->people->getDefaultAddress();
        $this->assertSame('742 Evergreen Terrace', $address->address);
        $this->assertSame('Springfield', $address->city);
        $this->assertSame('IL', $address->state);
        $this->assertSame('62704', $address->zip);

        $this->assertSame('742 Evergreen Terrace', $lead->get('street'));
        $this->assertEquals('62704', $lead->get('zip'));
        $this->assertSame('1985-04-12', $lead->get('Date_of_Birth'));
    }

    public function testExistingLeadUpdatesPersonAddressAndDob(): void
    {
        $zohoLeadId = 'zoho-' . uniqid();
        $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '742 Evergreen Terrace'));

        $lead = $this->syncAction($zohoLeadId)->execute($this->zohoRecord($zohoLeadId, '1 Infinite Loop'));

        $people = $lead->people->fresh();
        $this->assertSame('1 Infinite Loop', $people->getDefaultAddress()->address);
        $this->assertSame('1985-04-12', $people->dob->format('Y-m-d'));
        $this->assertSame('1 Infinite Loop', $lead->get('street'));
    }

    private function syncAction(string $zohoLeadId): SyncZohoLeadAction
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(CustomFieldEnum::FIELDS_MAP->value, self::FIELDS_MAP);

        new Setup($app, $user, $company)->run();

        return new SyncZohoLeadAction(
            $app,
            $company,
            $this->receiver(),
            $zohoLeadId
        );
    }

    private function receiver(): LeadReceiver
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return new CreateLeadReceiverAction(
            new LeadReceiverData(
                app: $app,
                branch: $company->defaultBranch,
                user: $user,
                agent: $user,
                name: 'zoho-test',
                source: 'zoho',
                isDefault: true
            )
        )->execute();
    }

    private function zohoRecord(string $zohoLeadId, string $street): Record
    {
        return new Record([
            'id' => $zohoLeadId,
            'First_Name' => 'Homer',
            'Last_Name' => 'Simpson',
            'Full_Name' => 'Homer Simpson',
            'Email' => 'homer-' . uniqid() . '@example.com',
            'Lead_Status' => 'New',
            'Street' => $street,
            'City' => 'Springfield',
            'State' => 'IL',
            'Zip_Code' => '62704',
            'Date_of_Birth' => '1985-04-12',
        ], Mockery::mock(Module::class));
    }
}
