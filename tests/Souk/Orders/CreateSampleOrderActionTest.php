<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateSampleOrderAction;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Tests\TestCase;

class CreateSampleOrderActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'inventory', 'crm'];

    public function test_creates_a_zero_dollar_draft_order_with_the_line(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'Kraken Elite 360 ' . uniqid(),
            ),
            $user,
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Marketing', 'lastname' => 'Sample']);

        $region = Regions::fromApp($app)->fromCompany($company)->notDeleted()->firstOrFail();
        $currency = Currencies::getByCode('USD');

        $order = new CreateSampleOrderAction(
            app: $app,
            company: $company,
            user: $user,
            region: $region,
            people: $people,
            currency: $currency,
            lines: [['variant' => $variant, 'quantity' => 1]],
            note: 'Free review unit',
        )->execute();

        $this->assertSame(OrderStatusEnum::DRAFT->value, $order->status);
        $this->assertSame(0.0, (float) $order->total_gross_amount);
        $this->assertGreaterThan(0, (int) $order->order_number);
        $this->assertSame((int) $people->getId(), (int) $order->people_id);

        $item = $order->items()->firstOrFail();
        $this->assertSame($variant->sku, $item->product_sku);
        $this->assertSame(0.0, (float) $item->unit_price_gross_amount);
        $this->assertSame(1.0, (float) $item->quantity);
    }
}
