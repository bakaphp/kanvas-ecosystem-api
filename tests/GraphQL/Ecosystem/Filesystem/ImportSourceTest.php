<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Filesystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Imports\Enums\ImportRunStatusEnum;
use Kanvas\Imports\Enums\ImportTemplateEnum;
use Kanvas\Imports\Jobs\RunImportSourceJob;
use Kanvas\Imports\Models\ImportSource;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\Ecosystem\Integration\Imports\Concerns\CreatesImportSources;
use Tests\TestCase;

final class ImportSourceTest extends TestCase
{
    use CreatesImportSources;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'inventory'];

    public function testCreatesACompanyConnectionWithAWriteOnlyPassword(): void
    {
        $created = $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            mutation($input: ImportConnectionInput!) {
                createImportConnection(input: $input) { id name driver host port is_app_wide }
            }
        ', ['input' => $this->connectionInput()]), 'createImportConnection');

        $this->assertSame('SFTP', $created['driver']);
        $this->assertSame(22, $created['port']);
        $this->assertFalse($created['is_app_wide']);

        $response = $this->graphQL(/** @lang GraphQL */ '
            query { importConnections { data { id password } } }
        ');
        $this->assertStringContainsString('Cannot query field "password"', (string) $response->json('errors.0.message'));
    }

    public function testRefusesAPrivateHost(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($input: ImportConnectionInput!) {
                createImportConnection(input: $input) { id }
            }
        ', ['input' => $this->connectionInput(['host' => '169.254.169.254'])]);

        $this->assertStringContainsString('non-public address', (string) $response->json('errors.0.message'));
    }

    public function testUpdatingWithoutAPasswordKeepsTheStoredOne(): void
    {
        $connection = $this->makeConnection([
            'companies_id' => $this->importUser()->getCurrentCompany()->getId(),
            'host' => '8.8.8.8',
        ]);

        $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            mutation($id: ID!, $input: ImportConnectionInput!) {
                updateImportConnection(id: $id, input: $input) { name }
            }
        ', ['id' => $connection->getId(), 'input' => ['name' => 'Renamed']]), 'updateImportConnection');

        $connection->refresh();
        $this->assertSame('Renamed', $connection->name);
        $this->assertSame('secret-password', $connection->password);
    }

    public function testListsTheCompanyConnectionsAndTheAppWideOnes(): void
    {
        $companyId = $this->importUser()->getCurrentCompany()->getId();
        $own = $this->makeConnection(['companies_id' => $companyId, 'name' => 'Own']);
        $appWide = $this->makeConnection(['name' => 'App-wide']);
        $foreign = $this->makeConnection(['companies_id' => $companyId + 100000, 'name' => 'Foreign']);

        $ids = array_map(
            'intval',
            array_column(
                $this->graphQLData($this->graphQL('query { importConnections(first: 100) { data { id } } }'), 'importConnections')['data'],
                'id'
            )
        );

        $this->assertContains($own->getId(), $ids);
        $this->assertContains($appWide->getId(), $ids);
        $this->assertNotContains($foreign->getId(), $ids);
    }

    public function testCannotDeleteAConnectionAnActiveImportUses(): void
    {
        $connection = $this->makeConnection(['companies_id' => $this->importUser()->getCurrentCompany()->getId()]);
        $this->makeSource([['pattern' => 'MP10425.csv']], connection: $connection);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($id: ID!) { deleteImportConnection(id: $id) }
        ', ['id' => $connection->getId()]);

        $this->assertStringContainsString('used by 1 active scheduled import', (string) $response->json('errors.0.message'));
    }

    public function testTestingUnsavedSettingsReportsTheFailureInsteadOfThrowing(): void
    {
        $result = $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            mutation($input: ImportConnectionInput!) {
                testImportConnection(input: $input) { connected message files }
            }
        ', ['input' => $this->connectionInput(['host' => '10.1.2.3'])]), 'testImportConnection');

        $this->assertFalse($result['connected']);
        $this->assertStringContainsString('non-public address', $result['message']);
        $this->assertSame([], $result['files']);
    }

    public function testCreatesAScheduledImportFromTheDealerTemplate(): void
    {
        $app = app(Apps::class);
        $company = $this->importUser()->getCurrentCompany();
        $this->setupInventory($app, $company, $this->importUser());
        $connection = $this->makeConnection();

        $source = $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            mutation($input: ImportSourceInput!) {
                createImportSourceFromTemplate(template: DEALER_VEHICLE_CSV, options: {price_source: "price_first"}, input: $input) {
                    id
                    name
                    unpublish_missing
                    effective_schedule
                    effective_timezone
                    files { pattern required filter { column in } }
                    mapper { name }
                    connection { id is_app_wide }
                    user { id }
                }
            }
        ', ['input' => [
            'name' => 'Griffin 437 nightly',
            'import_connection_id' => $connection->getId(),
            'warehouses_id' => Warehouses::fromApp($app)->fromCompany($company)->firstOrFail()->getId(),
            'channels_id' => Channels::fromApp($app)->fromCompany($company)->firstOrFail()->getId(),
            'files' => [
                ['pattern' => 'MP10426.csv'],
                ['pattern' => 'MP22154.csv', 'filter' => ['column' => 'New/Used', 'in' => ['Used', 'U']]],
            ],
        ]]), 'createImportSourceFromTemplate');

        $this->assertTrue($source['unpublish_missing'], 'Default from the template');
        $this->assertSame('0 1 * * *', $source['effective_schedule'], 'Inherited from the connection');
        $this->assertSame('America/New_York', $source['effective_timezone']);
        $template = ImportTemplateEnum::DEALER_VEHICLE_CSV->template();
        $this->assertSame(
            $template->mapperName($template->resolveOptions(['price_source' => 'price_first'])),
            $source['mapper']['name']
        );
        $this->assertTrue($source['connection']['is_app_wide']);
        $this->assertSame((string) $company->user->getId(), (string) $source['user']['id'], 'Runs as the company owner');
        $this->assertSame(['column' => 'New/Used', 'in' => ['Used', 'U']], $source['files'][1]['filter']);
    }

    public function testAPartialUpdateKeepsEverythingElse(): void
    {
        $source = $this->makeSource([['pattern' => 'MP10425.csv'], ['pattern' => 'MP22154.csv', 'required' => false]]);

        $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            mutation($id: ID!) {
                updateImportSource(id: $id, input: {schedule: "30 2 * * *", is_active: false}) { id }
            }
        ', ['id' => $source->getId()]), 'updateImportSource');

        $source->refresh();
        $this->assertSame('30 2 * * *', $source->schedule);
        $this->assertFalse($source->is_active);
        $this->assertCount(2, $source->files);
        $this->assertFalse($source->files[1]['required']);
    }

    public function testRejectsAMapperFromAnotherCompany(): void
    {
        $source = $this->makeSource([['pattern' => 'MP10425.csv']]);
        DB::connection('ecosystem')->table('filesystem_mappers')
            ->where('id', $source->filesystem_mapper_id)
            ->update(['companies_id' => $source->companies_id + 100000]);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($id: ID!) { updateImportSource(id: $id, input: {name: "x"}) { id } }
        ', ['id' => $source->getId()]);

        $this->assertNotNull($response->json('errors.0.message'));
    }

    public function testAMissingNameIsAValidationErrorNotAServerError(): void
    {
        $source = $this->makeSource([['pattern' => 'MP10425.csv']]);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation($input: ImportSourceInput!) { createImportSource(input: $input) { id } }
        ', ['input' => [
            'import_connection_id' => $source->import_connections_id,
            'filesystem_mapper_id' => $source->filesystem_mapper_id,
            'files' => [['pattern' => 'MP10425.csv']],
        ]]);

        $this->assertSame('A scheduled import needs a name.', $response->json('errors.0.message'));
    }

    public function testRunNowQueuesTheImport(): void
    {
        Queue::fake();
        $source = $this->makeSource([['pattern' => 'MP10425.csv']]);

        $result = $this->graphQLData($this->graphQL(/** @lang GraphQL */ '
            mutation($id: ID!) { runImportSource(id: $id) { last_status } }
        ', ['id' => $source->getId()]), 'runImportSource');

        $this->assertSame('QUEUED', $result['last_status']);
        Queue::assertPushed(RunImportSourceJob::class, fn (RunImportSourceJob $job) => $job->source->is($source));
        $this->assertSame(ImportRunStatusEnum::QUEUED, ImportSource::find($source->getId())->last_status);
    }

    private function connectionInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Dealer SFTP',
            'driver' => 'SFTP',
            'host' => '8.8.8.8',
            'username' => 'dealer',
            'password' => 'secret',
            'default_schedule' => '0 1 * * *',
            'timezone' => 'America/New_York',
        ], $overrides);
    }
}
