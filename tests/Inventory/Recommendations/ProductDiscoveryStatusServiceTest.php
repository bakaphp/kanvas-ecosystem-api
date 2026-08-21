<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\CheckProductDiscoverySetupTool;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryStatusService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProductDiscoveryStatusServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testEveryFailedCheckCarriesItsOwnFix(): void
    {
        $report = $this->report();

        foreach ($report['checks'] as $check) {
            if ($check['ok']) {
                continue;
            }

            // A list of gaps without remedies is not much help when the setup
            // order is the part people get wrong.
            $this->assertNotEmpty($check['fix'], "check '{$check['key']}' failed without a fix");
        }
    }

    public function testReportsEveryStepOfTheSetup(): void
    {
        $keys = array_column($this->report()['checks'], 'key');

        $this->assertSame([
            'search_engine',
            'typesense_credentials',
            'app_custom_product_index',
            'collection',
            'query_by',
            'catalog_strategy',
            'enrichment_agent',
            'blurb_coverage',
            'workflow_rule',
        ], $keys);
    }

    public function testAnUnconfiguredCompanyIsNotReady(): void
    {
        $report = new ProductDiscoveryStatusService(app(Apps::class), Companies::factory()->create())->report();

        $this->assertFalse($report['ready']);
    }

    public function testToolRefusesWithoutTenantContext(): void
    {
        $result = new CheckProductDiscoverySetupTool()->__invoke();

        // Typed context props would fatal; the tool has to answer the model
        // instead of throwing into the chat.
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('company context', $result['message']);
    }

    public function testToolReturnsChecksAndANoteThroughTheAgentContract(): void
    {
        /** @var Users $user */
        $user = auth()->user();

        $result = new CheckProductDiscoverySetupTool()
            ->withContext(app(Apps::class), $user->getCurrentCompany(), $user)
            ->__invoke();

        $this->assertSame('success', $result['status']);
        $this->assertIsBool($result['ready']);
        $this->assertNotEmpty($result['checks']);
        $this->assertNotEmpty($result['note']);
    }

    /**
     * @return array{ready: bool, checks: list<array{key: string, ok: bool, detail: string, fix: ?string}>}
     */
    private function report(): array
    {
        /** @var Users $user */
        $user = auth()->user();

        return new ProductDiscoveryStatusService(app(Apps::class), $user->getCurrentCompany())->report();
    }
}
