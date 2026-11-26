<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Connectors\Salesforce\Importers\SalesforceOrganizationsImporter;
use Kanvas\Connectors\Salesforce\Importers\SalesforcePeopleImporter;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\Actions\StartIntegrationImportAction;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportOption;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOptionTypeEnum;
use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;
use Kanvas\Workflow\Integrations\Imports\Importers\IntegrationImporter;
use Kanvas\Workflow\Integrations\Imports\Jobs\RunIntegrationImportJob;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;
use stdClass;
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

    public function testTheHandlerOffersOrganizationsThenPeople(): void
    {
        $importables = $this->integrationCompany->importables();

        $this->assertSame(['organizations', 'people'], array_column($importables, 'entity'));
        $this->assertSame(['Organizaciones', 'Contactos'], array_column($importables, 'label'));
        $this->assertEquals(
            [new ImportOption(name: 'then_people', type: ImportOptionTypeEnum::BOOLEAN, label: 'Importar contactos al terminar')],
            $importables[0]->options
        );
        $this->assertSame([], $importables[1]->options);
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

    public function testARecordWithAMalformedIdFailsAloneAndTheRestContinue(): void
    {
        $run = $this->runImport('organizations', [
            ['Id' => new stdClass(), 'Name' => 'Broken Record'],
            ['Id' => ['nested'], 'Name' => 'Broken Array'],
            'not a record',
            ['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp'],
        ]);

        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
        $this->assertSame(1, $run->created);
        $this->assertSame(3, $run->failed);
        $this->assertNotNull($this->organization('001xx0000000001AAA'));
    }

    public function testOrganizationsQueuePeopleWhenAskedTo(): void
    {
        Queue::fake();

        $run = $this->runImport('organizations', [['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp']], ['then_people' => true]);

        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
        $this->assertSame(['people' => 'queued'], $run->summary);
        $people = IntegrationImport::latestFor($this->integrationCompany, 'people');
        $this->assertSame(IntegrationImportStatusEnum::QUEUED, $people->status);
        $this->assertSame($this->kanvasUser->getId(), $people->users_id);
        Queue::assertPushed(RunIntegrationImportJob::class, fn (RunIntegrationImportJob $job) => $job->run->entity === 'people');
    }

    public function testOrganizationsLeavePeopleAloneByDefault(): void
    {
        Queue::fake();

        $run = $this->runImport('organizations', [['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp']]);

        $this->assertNull($run->summary);
        $this->assertNull(IntegrationImport::latestFor($this->integrationCompany, 'people'));
        Queue::assertNotPushed(RunIntegrationImportJob::class);
    }

    public function testOrganizationsReportWhyPeopleWereSkipped(): void
    {
        Queue::fake();
        $this->makeRun('people');

        $run = $this->runImport('organizations', [['Id' => '001xx0000000001AAA', 'Name' => 'Acme Corp']], ['then_people' => true]);

        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
        $this->assertSame(
            ['people' => 'skipped', 'reason' => 'A people import is already in progress for this integration.'],
            $run->summary
        );
    }

    public function testPeopleAreRefusedWhileOrganizationsAreImporting(): void
    {
        Queue::fake();
        $this->makeRun('organizations');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Organizations are still being imported for this integration. Import people when they finish.');

        new StartIntegrationImportAction($this->integrationCompany, $this->kanvasUser, 'people')->execute();
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

    private function runImport(string $entity, array $records, array $options = []): IntegrationImport
    {
        $run = $this->makeRun($entity, $options);
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

    private function makeRun(string $entity, array $options = []): IntegrationImport
    {
        return IntegrationImport::create([
            'apps_id' => $this->integrationCompany->apps_id,
            'companies_id' => $this->company->getId(),
            'integrations_id' => $this->integrationCompany->integrations_id,
            'integrations_companies_id' => $this->integrationCompany->getId(),
            'users_id' => $this->kanvasUser->getId(),
            'entity' => $entity,
            'status' => IntegrationImportStatusEnum::QUEUED,
            'options' => $options,
            'queued_at' => Carbon::now(),
        ]);
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
