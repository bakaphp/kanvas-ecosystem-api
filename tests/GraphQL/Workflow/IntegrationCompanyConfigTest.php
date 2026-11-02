<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;
use Tests\Workflow\Integration\Fixtures\AccountSelectingHandler;

/**
 * Background work that runs after an integration is saved has only the row to go on. What the tenant
 * selected has to be on it, and it has to be the latest selection.
 */
final class IntegrationCompanyConfigTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected $connectionsToTransact = [null, 'workflow', 'inventory'];

    private Regions $region;
    private Integrations $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $user = auth()->user();
        $this->region = $this->createDefaultRegion($user->getCurrentCompany(), app(Apps::class), $user);
        $this->integration = Integrations::create([
            'apps_id' => 0,
            'name' => uniqid('account_selecting_'),
            'handler' => AccountSelectingHandler::class,
            'config' => [],
        ]);
    }

    public function testSavingStoresTheSelectionUnderTheCurrentAppAndNoCredentials(): void
    {
        $row = $this->save(['token' => 'secret', 'accounts' => ['+15550000001']]);

        $this->assertSame(app(Apps::class)->getId(), $row->apps_id);
        $this->assertSame(['accounts' => ['+15550000001']], $row->config);
    }

    public function testSavingAgainUpdatesTheSameRowWithTheNewSelection(): void
    {
        $first = $this->save(['token' => 'secret', 'accounts' => ['+15550000001']]);
        $second = $this->save(['token' => 'secret', 'accounts' => ['+15550000002', '+15550000003']]);

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame(['accounts' => ['+15550000002', '+15550000003']], $second->config);
    }

    /**
     * Before the update path existed the row kept the status of its first save for good, so a tenant
     * whose first attempt failed could never fix it by correcting the credentials.
     */
    public function testSavingAgainReflectsTheLatestSetupResult(): void
    {
        $failed = $this->save(['fail' => true]);
        $this->assertSame($this->statusId(StatusEnum::FAILED), $failed->status_id);

        $fixed = $this->save(['accounts' => ['+15550000001']]);

        $this->assertSame($failed->getId(), $fixed->getId());
        $this->assertSame($this->statusId(StatusEnum::ACTIVE), $fixed->status_id);
    }

    private function save(array $config): IntegrationsCompany
    {
        $response = $this->graphQL('
            mutation($input: IntegrationsCompaniesInput!) {
                integrationCompany(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'integration' => ['id' => $this->integration->getId()],
                'region' => ['id' => $this->region->getId()],
                'company_id' => auth()->user()->getCurrentCompany()->getId(),
                'config' => $config,
            ],
        ]);

        return IntegrationsCompany::getById((int) $this->graphQLData($response, 'integrationCompany')['id']);
    }

    private function statusId(StatusEnum $status): int
    {
        return Status::where('slug', $status->value)
            ->where('apps_id', 0)
            ->firstOrFail()
            ->getId();
    }
}
