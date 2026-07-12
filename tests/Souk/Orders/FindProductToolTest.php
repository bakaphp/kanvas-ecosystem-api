<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\FindProductTool;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
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
}
