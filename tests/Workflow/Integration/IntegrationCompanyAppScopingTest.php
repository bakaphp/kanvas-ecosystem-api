<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\Actions\RemoveIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\Actions\UpdateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany as IntegrationsCompanyData;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

/**
 * A company can live in several apps. Before integration_companies carried the app, the same
 * company + integration + region in two apps was one row — one set of credentials and accounts
 * shared by two tenants that never agreed to share anything.
 */
final class IntegrationCompanyAppScopingTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected $connectionsToTransact = [null, 'workflow', 'inventory'];

    private Apps $kanvasApp;
    private Apps $otherApp;
    private Companies $company;
    private Regions $region;
    private Integrations $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        // Only its id is read, and integration_companies.apps_id has no foreign key.
        $this->otherApp = new Apps()->forceFill(['id' => $this->kanvasApp->getId() + 900000]);

        $user = auth()->user();
        $this->company = $user->getCurrentCompany();
        $this->region = $this->createDefaultRegion($this->company, $this->kanvasApp, $user);
        $this->integration = Integrations::create([
            'apps_id' => 0,
            'name' => uniqid('app_scoping_'),
            'handler' => 'none',
        ]);
    }

    public function testCreateInSecondAppMakesSecondRow(): void
    {
        $first = $this->createFor($this->kanvasApp);
        $second = $this->createFor($this->otherApp);

        $this->assertNotSame($first->getId(), $second->getId());
        $this->assertSame($this->kanvasApp->getId(), $first->fresh()->apps_id);
        $this->assertSame($this->otherApp->getId(), $second->fresh()->apps_id);
    }

    public function testCreatingTwiceInTheSameAppReturnsTheSameRow(): void
    {
        $this->assertSame(
            $this->createFor($this->kanvasApp)->getId(),
            $this->createFor($this->kanvasApp)->getId()
        );
    }

    public function testDuplicateInSameAppRejectedByUniqueIndex(): void
    {
        $this->createFor($this->kanvasApp);

        $this->expectException(QueryException::class);

        IntegrationsCompany::create($this->identity($this->kanvasApp->getId()));
    }

    /**
     * MySQL treats NULLs as distinct under a unique index, which is what lets rows written before
     * the column existed coexist — including several of them for the same company.
     */
    public function testLegacyRowsCoexistUnderTheUniqueIndex(): void
    {
        $first = IntegrationsCompany::create($this->identity(null));
        $second = IntegrationsCompany::create($this->identity(null));

        $this->assertNotSame($first->getId(), $second->getId());
    }

    public function testCreateDoesNotAdoptUnresolvedLegacyRow(): void
    {
        $legacy = IntegrationsCompany::create($this->identity(null));

        $created = $this->createFor($this->kanvasApp);

        $this->assertNotSame($legacy->getId(), $created->getId());
        $this->assertNull($legacy->fresh()->apps_id);
    }

    public function testAppScopedReadReturnsOnlyThatAppsRow(): void
    {
        $mine = $this->createFor($this->kanvasApp);
        $theirs = $this->createFor($this->otherApp);

        $this->assertSame($mine->getId(), $this->readFor($this->kanvasApp)?->getId());
        $this->assertSame($theirs->getId(), $this->readFor($this->otherApp)?->getId());
    }

    public function testAppScopedReadNeverFallsBackToALegacyRow(): void
    {
        IntegrationsCompany::create($this->identity(null));

        $this->assertNull($this->readFor($this->kanvasApp));
    }

    public function testAppBCannotResolveAppAsRow(): void
    {
        $this->createFor($this->kanvasApp);

        $this->assertNull($this->readFor($this->otherApp));
    }

    public function testRemoveMatchesOnlyTheRowsOwnApp(): void
    {
        $theirs = $this->createFor($this->otherApp);

        try {
            new RemoveIntegrationCompanyAction($theirs, $this->kanvasApp)->execute();
            $this->fail('An app removed another app\'s integration.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(IntegrationsCompany::query()->whereKey($theirs->getKey())->exists());
        }
    }

    public function testUpdateMatchesOnlyTheRowsOwnApp(): void
    {
        $theirs = $this->createFor($this->otherApp);

        $this->expectException(ModelNotFoundException::class);

        new UpdateIntegrationCompanyAction(
            $theirs,
            $this->data($this->kanvasApp, ['accounts' => ['+15550000001']]),
            $this->workflowStatus(StatusEnum::ACTIVE)
        )->execute();
    }

    public function testToggleThroughTheMutationMatchesOnlyTheRowsOwnApp(): void
    {
        $theirs = $this->createFor($this->otherApp);

        $this->graphQL('
            mutation($input: IntegrationCompanyActive!) {
                integrationCompanyIsActive(input: $input)
            }
        ', ['input' => ['id' => $theirs->getId(), 'is_active' => false]])
            ->assertJsonMissingPath('data.integrationCompanyIsActive');

        $this->assertTrue($theirs->fresh()->is_active);
    }

    /**
     * Until an owner is established for it, a row with no recorded app has to stay removable — the
     * alternative is every integration connected before this release becoming undisconnectable.
     */
    public function testLegacyRowStaysManageableUntilItsOwnerIsEstablished(): void
    {
        $legacy = IntegrationsCompany::create($this->identity(null));

        $this->assertTrue(new RemoveIntegrationCompanyAction($legacy, $this->kanvasApp)->execute());
    }

    public function testCreatePersistsWhatTheHandlerAskedToKeep(): void
    {
        $created = new CreateIntegrationCompanyAction(
            $this->data($this->kanvasApp, ['accounts' => ['+15550000001']]),
            auth()->user(),
            $this->workflowStatus(StatusEnum::ACTIVE)
        )->execute();

        $this->assertSame(['accounts' => ['+15550000001']], $created->fresh()->config);
    }

    public function testUpdateWritesStatusAndConfigTogether(): void
    {
        $created = $this->createFor($this->kanvasApp);
        $failed = $this->workflowStatus(StatusEnum::FAILED);

        new UpdateIntegrationCompanyAction(
            $created,
            $this->data($this->kanvasApp, ['accounts' => ['+15550000002']]),
            $failed
        )->execute();

        $fresh = $created->fresh();
        $this->assertSame($failed->getId(), $fresh->status_id);
        $this->assertSame(['accounts' => ['+15550000002']], $fresh->config);
    }

    private function createFor(Apps $app): IntegrationsCompany
    {
        return new CreateIntegrationCompanyAction(
            $this->data($app),
            auth()->user(),
            $this->workflowStatus(StatusEnum::ACTIVE)
        )->execute();
    }

    private function readFor(Apps $app): ?IntegrationsCompany
    {
        return IntegrationsCompany::getByIntegration(
            company: $this->company,
            status: $this->workflowStatus(StatusEnum::ACTIVE),
            name: $this->integration->name,
            region: $this->region,
            app: $app
        );
    }

    private function data(Apps $app, array $persistedConfig = []): IntegrationsCompanyData
    {
        return new IntegrationsCompanyData(
            app: $app,
            integration: $this->integration,
            company: $this->company,
            region: $this->region,
            config: [],
            persistedConfig: $persistedConfig
        );
    }

    /**
     * @return array<string, int|null>
     */
    private function identity(?int $appsId): array
    {
        return [
            'apps_id' => $appsId,
            'companies_id' => $this->company->getId(),
            'integrations_id' => $this->integration->getId(),
            'region_id' => $this->region->getId(),
            'status_id' => $this->workflowStatus(StatusEnum::ACTIVE)->getId(),
        ];
    }

    private function workflowStatus(StatusEnum $status): Status
    {
        return Status::where('slug', $status->value)
            ->where('apps_id', 0)
            ->firstOrFail();
    }
}
