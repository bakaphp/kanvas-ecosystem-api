<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Connectors\NetSuite\Enums\ImportStatusEnum;
use Kanvas\Connectors\NetSuite\Jobs\ImportNetSuiteDataJob;
use Kanvas\Connectors\NetSuite\Services\NetSuiteImportStatusService;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Users\Models\Users;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the mutation acts on the logged-in user's company, which every process shares, and its
 * import status and saved-search settings live in Redis, which no transaction rolls back.
 */
#[Group('serial')]
class NetSuiteImportMutationTest extends TestCase
{
    private const string IMPORT = '
        mutation($input: NetSuiteImportInput!) {
            netsuiteImport(input: $input) { entity status saved_search_id }
        }
    ';

    private const string STATUS = '
        query($entity: NetSuiteImportEntity!) {
            netsuiteImportStatus(entity: $entity) { entity status total processed created failed }
        }
    ';

    private Companies $company;
    private mixed $originalDefaultLocation;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Users $user */
        $user = auth()->user();
        $this->company = $user->getCurrentCompany();
        $this->forgetSettings();

        // Products and stock refuse to queue for a company with no warehouse mapped to a location.
        new InventorySetup(app(Apps::class), $user, $this->company)->run();
        $this->originalDefaultLocation = $this->company->get(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value);
        $this->company->set(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value, '7');

        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->forgetSettings();
        $this->originalDefaultLocation === null
            ? $this->company->del(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value)
            : $this->company->set(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value, $this->originalDefaultLocation);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testTheImportIsQueuedAndReportedAsQueued(): void
    {
        $this->graphQL(self::IMPORT, ['input' => ['entity' => 'PRODUCTS', 'saved_search_id' => '901']])
            ->assertSuccessful()
            ->assertJson(['data' => ['netsuiteImport' => [
                'entity' => 'PRODUCTS',
                'status' => 'QUEUED',
                'saved_search_id' => '901',
            ]]]);

        Queue::assertPushed(
            ImportNetSuiteDataJob::class,
            fn (ImportNetSuiteDataJob $job) => $job->entity === ImportEntityEnum::PRODUCTS
                && $job->savedSearchId === '901'
                && $job->offset === 0
                && $job->company->getId() === $this->company->getId()
        );

        $this->graphQL(self::STATUS, ['entity' => 'PRODUCTS'])
            ->assertSuccessful()
            ->assertJson(['data' => ['netsuiteImportStatus' => ['entity' => 'PRODUCTS', 'status' => 'QUEUED']]]);
    }

    public function testAnEntityThatWasNeverImportedHasNoStatus(): void
    {
        $this->graphQL(self::STATUS, ['entity' => 'CUSTOMERS'])
            ->assertSuccessful()
            ->assertJson(['data' => ['netsuiteImportStatus' => null]]);
    }

    public function testASecondImportWhileOneIsRunningIsRefused(): void
    {
        $this->graphQL(self::IMPORT, ['input' => ['entity' => 'CUSTOMERS']])->assertSuccessful();

        $this->graphQL(self::IMPORT, ['input' => ['entity' => 'CUSTOMERS']])
            ->assertGraphQLErrorMessage('A NetSuite customers import is already in progress.');

        Queue::assertPushed(ImportNetSuiteDataJob::class, 1);
    }

    public function testARunThatStoppedReportingLongAgoDoesNotBlockANewOne(): void
    {
        Carbon::setTestNow(now()->subHours(NetSuiteImportStatusService::STALE_AFTER_HOURS + 1));
        new NetSuiteImportStatusService($this->company, ImportEntityEnum::CUSTOMERS)->queue(null);
        Carbon::setTestNow();

        $this->graphQL(self::IMPORT, ['input' => ['entity' => 'CUSTOMERS']])->assertSuccessful();

        Queue::assertPushed(ImportNetSuiteDataJob::class, 1);
    }

    public function testLocationsCanOnlyBeChosenForProducts(): void
    {
        $this->graphQL(self::IMPORT, ['input' => ['entity' => 'CUSTOMERS', 'location_ids' => ['7']]])
            ->assertGraphQLErrorMessage('location_ids only applies to a PRODUCTS import.');

        Queue::assertNothingPushed();
    }

    public function testStockWithoutASavedSearchIsRefused(): void
    {
        $this->graphQL(self::IMPORT, ['input' => ['entity' => 'STOCK']])
            ->assertGraphQLErrorMessage(
                'A NetSuite stock import needs a saved search. Pass saved_search_id or set '
                . ConfigurationEnum::NET_SUITE_STOCK_SAVED_SEARCH_ID->value . '.'
            );

        Queue::assertNothingPushed();
    }

    public function testTheCompanySettingIsUsedWhenTheInputNamesNoSavedSearch(): void
    {
        $this->company->set(ConfigurationEnum::NET_SUITE_STOCK_SAVED_SEARCH_ID->value, '576');

        $this->graphQL(self::IMPORT, ['input' => ['entity' => 'STOCK', 'create_missing' => true]])
            ->assertSuccessful()
            ->assertJson(['data' => ['netsuiteImport' => ['saved_search_id' => '576']]]);

        Queue::assertPushed(
            ImportNetSuiteDataJob::class,
            fn (ImportNetSuiteDataJob $job) => $job->savedSearchId === '576' && $job->createMissing
        );
    }

    private function forgetSettings(): void
    {
        foreach (ImportEntityEnum::cases() as $entity) {
            $this->company->del($entity->statusKey());
            $this->company->del($entity->savedSearchSetting()->value);
        }
    }
}
