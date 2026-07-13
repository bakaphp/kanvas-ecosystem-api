<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Intelligence\Agents\Neuron\Tools\Sales\CreateSampleOrderTool;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Tests\TestCase;

class CreateSampleOrderToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'inventory', 'crm'];

    public function test_creates_a_draft_sample_order_and_a_new_customer(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(app: $app, company: $company, user: $user, name: 'Kraken ' . uniqid()),
            $user,
        )->execute();
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $result = new CreateSampleOrderTool()->withContext($app, $company, $user)->__invoke(
            customer_email: 'reviewer@yt.test',
            customer_name: 'Linus Reviewer',
            sku: (string) $variant->sku,
            quantity: 2,
            note: 'YT review unit',
        );

        $this->assertTrue($result['created']);
        $this->assertSame($variant->sku, $result['sku']);
        $this->assertSame(2, $result['quantity']);
        $this->assertGreaterThan(0, (int) $result['order_number']);
        $this->assertNotNull(PeoplesRepository::getByEmail('reviewer@yt.test', $company, $app));
    }

    public function test_returns_a_reason_when_the_sku_is_not_synced(): void
    {
        $user = auth()->user();
        $result = new CreateSampleOrderTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user)->__invoke(
            customer_email: 'x@y.test',
            customer_name: 'Nobody',
            sku: 'NOT-A-REAL-SKU-' . uniqid(),
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('sync', $result['reason']);
    }
}
