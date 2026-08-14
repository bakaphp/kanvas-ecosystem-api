<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\CreateSampleOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindSalesOrderTool;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

class FindProductToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'inventory', 'crm'];

    public function test_finds_a_variant_by_product_name_and_returns_its_sku(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $name = 'Kraken Elite ' . uniqid();
        $product = new CreateProductAction(
            new ProductDto(app: $app, company: $company, user: $user, name: $name),
            $user,
        )->execute();
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $result = new FindProductTool()->withContext($app, $company, $user)->__invoke(query: 'Kraken Elite');

        $this->assertGreaterThanOrEqual(1, (int) $result['count']);
        $skus = array_column($result['products'], 'sku');
        $this->assertContains($variant->sku, $skus);
    }

    public function test_returns_empty_when_nothing_matches(): void
    {
        $user = auth()->user();
        $result = new FindProductTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user)
            ->__invoke(query: 'NO-SUCH-PRODUCT-' . uniqid());

        $this->assertSame(0, (int) $result['count']);
        $this->assertSame([], $result['products']);
    }

    /**
     * Quoting a multi-line order means calling these once per line, so each must key its run budget by
     * inputs — otherwise the 11th DISTINCT call in a turn trips NeuronAI's per-tool-name cap and aborts
     * the whole turn (Sentry KANVAS-ECOSYSTEM-64Q).
     */
    public function test_commerce_per_record_tools_key_their_run_budget_by_inputs(): void
    {
        $tools = [
            new FindProductTool(),
            new FindSalesOrderTool(),
            new CreateSampleOrderTool(),
        ];

        foreach ($tools as $tool) {
            $this->assertInstanceOf(HasRunKey::class, $tool, $tool->getName() . ' must key its run budget by inputs.');

            $tool->setInputs(['query' => 'Kraken Elite', 'order_number' => 'SO-1', 'sku' => 'RL-KP336']);
            $keyOne = $tool->getRunKey();

            $tool->setInputs(['query' => 'Kraken Mini', 'order_number' => 'SO-2', 'sku' => 'RL-KP337']);
            $keyTwo = $tool->getRunKey();

            $tool->setInputs(['query' => 'Kraken Elite', 'order_number' => 'SO-1', 'sku' => 'RL-KP336']);
            $keyOneAgain = $tool->getRunKey();

            $this->assertNotEquals($keyOne, $keyTwo, $tool->getName() . ': distinct records must not share a run budget.');
            $this->assertEquals($keyOneAgain, $keyOne, $tool->getName() . ': identical calls must collapse so a loop is still capped.');
        }
    }
}
