<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Connectors\Salesforce\Importers\SalesforceOrganizationsImporter;
use Kanvas\Connectors\Salesforce\Importers\SalesforcePeopleImporter;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;
use Kanvas\Workflow\Integrations\Imports\Importers\IntegrationImporter;
use Kanvas\Workflow\Integrations\Imports\Jobs\RunIntegrationImportJob;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;
use Tests\Connectors\Integration\Salesforce\Concerns\CreatesSalesforceIntegration;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

final class SalesforceImportersTest extends TestCase
{
    use CreatesSalesforceIntegration;
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social', 'workflow'];

    private Companies $company;
    private Users $kanvasUser;
    private IntegrationsCompany $integrationCompany;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $this->company = $this->freshCompany();
        $this->integrationCompany = $this->salesforceIntegrationFor($this->company);
    }

    public function testTheHandlerOffersOrganizationsAndPeopleWithoutOptions(): void
    {
        $importables = $this->integrationCompany->importables();

        $this->assertSame(['organizations', 'people'], array_column($importables, 'entity'));
        $this->assertSame(['Organizaciones', 'Contactos'], array_column($importables, 'label'));
        $this->assertSame([[], []], array_column($importables, 'options'));
        $this->assertInstanceOf(
            SalesforceOrganizationsImporter::class,
            $this->integrationCompany->importerFor('organizations', $this->kanvasUser)
        );
        $this->assertInstanceOf(
            SalesforcePeopleImporter::class,
            $this->integrationCompany->importerFor('people', $this->kanvasUser)
        );
    }

    public function testOrganizationsAreImportedFromTheListedAccounts(): void
    {
        $run = $this->runImport('organizations', [
            ['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp'],
            ['Id' => '001xx0000000002AAA', 'Name' => 'Globex Corp'],
        ]);

        $acme = $this->organization('001xx0000000001AAA');
        $globex = $this->organization('001xx0000000002AAA');
        $this->assertSame('Acme Corp', $acme->name);
        $this->assertSame('Globex Corp', $globex->name);
        $this->assertSame(2, $run->created);
        $this->assertSame(0, $run->failed);
    }

    public function testPeopleAreImportedAndLinkedToTheirAccount(): void
    {
        $this->runImport('organizations', [['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp']]);

        $run = $this->runImport('people', [
            ['Id' => '003xx0000000001AAA', 'FirstName' => 'John', 'LastName' => 'Appleseed', 'AccountId' => '001xx0000000001AAA'],
            ['Id' => '003xx0000000002AAA', 'FirstName' => 'Jane', 'LastName' => 'Doe'],
        ]);

        $john = $this->person('003xx0000000001AAA');
        $jane = $this->person('003xx0000000002AAA');
        $this->assertSame('John', $john->firstname);
        $this->assertSame('Jane', $jane->firstname);
        $this->assertTrue(
            $this->organization('001xx0000000001AAA')->peoples()->where('peoples.id', $john->getId())->exists()
        );
        $this->assertSame(2, $run->created);
    }

    public function testARecordWithoutAnIdFailsAloneAndTheRestContinue(): void
    {
        $run = $this->runImport('organizations', [
            ['Name' => 'Broken Record'],
            ['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp'],
        ]);

        $this->assertNotNull($this->organization('001xx0000000001AAA'));
        $this->assertFalse(
            Organization::query()->fromCompany($this->company)->where('name', 'Broken Record')->exists()
        );
        $this->assertSame(1, $run->created);
        $this->assertSame(1, $run->failed);
        $this->assertStringContainsString('Salesforce record is missing an Id', json_encode($run->getErrors()));
    }

    public function testARerunCountsExistingRecordsAsUpdated(): void
    {
        $records = [['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp']];
        $this->runImport('organizations', $records);

        $second = $this->runImport('organizations', [['Id' => '001xx0000000001AAA', 'Name' => 'Acme Renamed']]);

        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->updated);
        $this->assertSame('Acme Renamed', $this->organization('001xx0000000001AAA')->name);
    }

    private function runImport(string $entity, array $records): IntegrationImport
    {
        $run = IntegrationImport::create([
            'apps_id' => $this->integrationCompany->apps_id,
            'companies_id' => $this->company->getId(),
            'integrations_id' => $this->integrationCompany->integrations_id,
            'integrations_companies_id' => $this->integrationCompany->getId(),
            'users_id' => $this->kanvasUser->getId(),
            'entity' => $entity,
            'status' => IntegrationImportStatusEnum::QUEUED,
            'options' => [],
            'queued_at' => Carbon::now(),
        ]);
        $importer = $entity === 'people'
            ? new class ($this->integrationCompany, $this->kanvasUser, $records) extends SalesforcePeopleImporter {
                public function __construct(IntegrationsCompany $integrationCompany, Users $user, private readonly array $records)
                {
                    parent::__construct($integrationCompany, $user);
                }

                #[Override]
                protected function pullAll(): array
                {
                    return $this->records;
                }
            }
            : new class ($this->integrationCompany, $this->kanvasUser, $records) extends SalesforceOrganizationsImporter {
                public function __construct(IntegrationsCompany $integrationCompany, Users $user, private readonly array $records)
                {
                    parent::__construct($integrationCompany, $user);
                }

                #[Override]
                protected function pullAll(): array
                {
                    return $this->records;
                }
            };

        new class ($run, $importer) extends RunIntegrationImportJob {
            public function __construct(IntegrationImport $run, private readonly IntegrationImporter $seamImporter)
            {
                parent::__construct($run);
            }

            #[Override]
            protected function importer(): IntegrationImporter
            {
                return $this->seamImporter;
            }
        }->handle();

        return $run->refresh();
    }

    private function organization(string $salesforceId): ?Organization
    {
        return Organization::getByCustomFieldTransactionSafe(
            CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value,
            $salesforceId,
            $this->company
        );
    }

    private function person(string $salesforceId): ?People
    {
        return People::getByCustomFieldTransactionSafe(
            CustomFieldEnum::SALESFORCE_CONTACT_ID->value,
            $salesforceId,
            $this->company
        );
    }
}
