<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ELead;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\ProcessDriversLicenseAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Enums\PeopleCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

class ProcessDriversLicenseActionTest extends TestCase
{
    public function testReturnsNullAndSetsNoFlagWhenMessageHasNoDriversLicense(): void
    {
        $lead = $this->makeLead();

        $result = new ProcessDriversLicenseAction($lead)->execute([
            'verb' => 'get-docs',
            'status' => 'submitted',
            'data' => [
                '9' => ['type' => ['id' => 9, 'name' => 'Proof of Income'], 'files' => []],
            ],
        ]);

        $this->assertNull($result);
        $this->assertNull($lead->fresh()->get(CustomFieldEnum::GET_DOCS_IMPORTER->value));
    }

    public function testSetsImporterFlagAndUpdatesPeopleWhenDriversLicenseSubmitted(): void
    {
        $lead = $this->makeLead();
        $driverLicenseData = $this->driversLicenseData();
        $lead->people->set(PeopleCustomFieldEnum::DRIVERS_LICENSE->value, $driverLicenseData);

        $result = new ProcessDriversLicenseAction($lead)->execute($this->getDocsMessage());

        $this->assertIsArray($result);
        $this->assertSame($driverLicenseData['license'], $result['license']);
        $this->assertSame($driverLicenseData['state'], $result['state']);
        $this->assertSame('2030-01-01', $result['exp_date']);
        $this->assertSame('1990-01-01', $result['birthday']);

        $flag = $lead->fresh()->get(CustomFieldEnum::GET_DOCS_IMPORTER->value);
        $this->assertIsArray($flag);
        $this->assertSame(1, $flag['active']);
        $this->assertSame('Drivers License Ready to be imported into eLead.', $flag['message']);

        $people = $lead->people->fresh();
        $this->assertSame($driverLicenseData['license'], $people->license_number);
        $this->assertSame('2030-01-01', $people->license_expiration_date->format('Y-m-d'));
        $this->assertSame($driverLicenseData['state'], $people->license_state);
    }

    public function testFlagIsSetEvenWhenNoExtractedDriversLicenseDataYet(): void
    {
        $lead = $this->makeLead();

        $result = new ProcessDriversLicenseAction($lead)->execute($this->getDocsMessage());

        $this->assertNull($result);
        $this->assertIsArray($lead->fresh()->get(CustomFieldEnum::GET_DOCS_IMPORTER->value));
    }

    /**
     * @return array<string, mixed>
     */
    private function getDocsMessage(): array
    {
        return [
            'verb' => 'get-docs',
            'status' => 'submitted',
            // 3 is the drivers-license slot in a get-docs payload.
            'data' => [
                '3' => ['type' => ['id' => 3, 'name' => 'Drivers License'], 'files' => []],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function driversLicenseData(): array
    {
        return [
            'address' => '123 TEST ST, SAMPLE CITY, IN, 46327',
            'license' => '0000-00-0000',
            'state' => 'IN',
            'birthday' => ['day' => 1, 'month' => 1, 'year' => 1990],
            'exp_date' => ['day' => 1, 'month' => 1, 'year' => 2030],
            'firstname' => 'TEST',
            'middlename' => 'SAMPLE',
            'lastname' => 'USER',
        ];
    }

    private function makeLead(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return Lead::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
    }
}
