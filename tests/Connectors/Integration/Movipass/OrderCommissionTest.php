<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\CalculateOrderCommissionAction;
use Kanvas\Souk\Orders\Actions\GetOrderCommissionStatsAction;
use Kanvas\Connectors\Movipass\Actions\ResolveCommissionRateAction;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Enums\CommissionEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class OrderCommissionTest extends TestCase
{
    use HasIntegrationCompany;
    use InventoryCases;
    use PaymentCases;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $user = Auth::user();
        if ($user) {
            $company = $user->getCurrentCompany();
            $company->del(ConfigurationEnum::DEFAULT_COMMISSION_RATE->value);
        }

        parent::tearDown();
    }

    private function createMovipassOrder(Apps $app, array $productAttributes = []): Order
    {
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $this->setIntegration(
            $app,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $company,
            $user
        );

        $this->setAllowNoPaymentStatus(true, $app);

        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::fromApp($app)->find($productResponse['id']);
        $variant = $product->variants->first();

        foreach ($productAttributes as $attrName => $attrValue) {
            $attribute = Attributes::firstOrCreate(
                [
                    'slug' => $attrName,
                    'apps_id' => $app->getId(),
                    'companies_id' => $company->getId(),
                ],
                [
                    'name' => $attrName,
                    'users_id' => $user->getId(),
                ]
            );

            new AddAttributeAction($variant, $attribute, $attrValue)->execute();
        }

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: (string) $variant->id,
            channelId: $channelResponse['id'],
            warehouseData: ['id' => $warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $variant->id,
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $data = [
            'cartId' => 0,
            'customer' => [
                'email' => fake()->email(),
            ],
            'order_type' => OrderTypeEnum::MOVIPASS->value,
            'metadata' => [
                'data' => [
                    'vehiclePlate' => 'T000001',
                    'vehicleBrand' => 'Toyota',
                    'vehicleColor' => 'red',
                    'is_manual' => false,
                ],
            ],
            'items' => [
                [
                    'variant_id' => (string) $variant->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
            'reference' => 'Commission test order',
        ];

        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                    }
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $orderId = $response->json('data.createOrderFromCart.order.id');

        return Order::fromApp($app)->find($orderId);
    }

    public function testResolveCommissionRateFromVariant(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app, [
            CommissionEnum::COMMISSION_RATE->value => '10',
        ]);

        $result = new ResolveCommissionRateAction($order)->execute();

        $this->assertEquals(10.0, $result['rate']);
        $this->assertEquals('variant', $result['source']);
    }

    public function testResolveCommissionRateFallbackToProduct(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $order = $this->createMovipassOrder($app);

        $variant = $order->items()->first()->variant;
        $product = $variant->product;

        $attribute = Attributes::firstOrCreate(
            [
                'slug' => CommissionEnum::COMMISSION_RATE->value,
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
            ],
            [
                'name' => CommissionEnum::COMMISSION_RATE->value,
                'users_id' => $user->getId(),
            ]
        );

        $productAttribute = $product->attributes()
            ->where('attributes.id', $attribute->getId())
            ->first();

        if ($productAttribute === null) {
            $productAttribute = new ProductsAttributes();
            $productAttribute->products_id = $product->getId();
            $productAttribute->attributes_id = $attribute->getId();
            $productAttribute->value = '12';
            $productAttribute->saveOrFail();
        } else {
            $productAttribute->value = '12';
            $productAttribute->saveOrFail();
        }

        $result = new ResolveCommissionRateAction($order)->execute();

        $this->assertEquals(12.0, $result['rate']);
        $this->assertEquals('product', $result['source']);
    }

    public function testResolveCommissionRateFallbackToCompany(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $company->set(ConfigurationEnum::DEFAULT_COMMISSION_RATE->value, '15.00');

        $order = $this->createMovipassOrder($app);

        $result = new ResolveCommissionRateAction($order)->execute();

        $this->assertEquals(15.0, $result['rate']);
        $this->assertEquals('company_config', $result['source']);
    }

    public function testResolveCommissionRateDefaultsToZero(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app);

        $result = new ResolveCommissionRateAction($order)->execute();

        $this->assertEquals(0.0, $result['rate']);
        $this->assertEquals('default', $result['source']);
    }

    public function testCalculateOrderCommission(): void
    {
        $app = app(Apps::class);
        $order = $this->createMovipassOrder($app, [
            CommissionEnum::COMMISSION_RATE->value => '20',
        ]);

        $updatedOrder = new CalculateOrderCommissionAction($order)->execute();

        $net = (float) $order->total_net_amount;
        $expectedCommission = round($net * 20 / 100, 2);
        $expectedProvider = $net - $expectedCommission;

        $this->assertEquals(20.0, $updatedOrder->commission_rate);
        $this->assertEquals($expectedCommission, $updatedOrder->commission_amount);
        $this->assertEquals($expectedProvider, $updatedOrder->provider_amount);
        $this->assertNotNull($updatedOrder->metadata['commission']);
        $this->assertEquals(20.0, $updatedOrder->metadata['commission']['rate']);
        $this->assertEquals($expectedCommission, $updatedOrder->metadata['commission']['amount']);
        $this->assertEquals($expectedProvider, $updatedOrder->metadata['commission']['provider_amount']);
        $this->assertEquals('variant', $updatedOrder->metadata['commission']['resolved_from']);
    }

    public function testCommissionOnDiscountedOrder(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app, [
            CommissionEnum::COMMISSION_RATE->value => '10',
        ]);

        $grossAmount = (float) $order->total_gross_amount;
        $manualDiscount = $grossAmount * 0.20;

        $order->discount_amount = $manualDiscount;
        $order->total_net_amount = $grossAmount - $manualDiscount;
        $order->saveOrFail();
        $order->refresh();

        $updatedOrder = new CalculateOrderCommissionAction($order)->execute();

        $net = (float) $order->total_net_amount;
        $expectedCommission = round($net * 10 / 100, 2);
        $expectedProvider = $net - $expectedCommission;

        $this->assertEquals(10.0, $updatedOrder->commission_rate);
        $this->assertEquals($expectedCommission, $updatedOrder->commission_amount);
        $this->assertEquals($expectedProvider, $updatedOrder->provider_amount);
        $this->assertLessThan($grossAmount * 0.10, $updatedOrder->commission_amount);
    }

    public function testCommissionRecalculationOnLateFee(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app, [
            CommissionEnum::COMMISSION_RATE->value => '15',
        ]);

        new CalculateOrderCommissionAction($order)->execute();
        $order->refresh();

        $order->shipping_price_gross_amount = 20.00;
        $order->shipping_price_net_amount = 20.00;
        $order->saveOrFail();
        $order->calculateTotal();
        $order->refresh();

        $this->assertEquals(15.0, $order->commission_rate);
        $this->assertNotNull($order->commission_amount);
        $this->assertNotNull($order->provider_amount);

        $net = (float) $order->total_net_amount;
        $expectedCommission = round($net * 15 / 100, 2);

        $this->assertEquals($expectedCommission, $order->commission_amount);
        $this->assertEquals($net - $expectedCommission, $order->provider_amount);
    }

    public function testCommissionStatsAggregation(): void
    {
        $app = app(Apps::class);

        $order1 = $this->createMovipassOrder($app, [
            CommissionEnum::COMMISSION_RATE->value => '10',
        ]);
        new CalculateOrderCommissionAction($order1)->execute();

        $order2 = $this->createMovipassOrder($app, [
            CommissionEnum::COMMISSION_RATE->value => '10',
        ]);
        new CalculateOrderCommissionAction($order2)->execute();

        $order1->refresh();
        $order2->refresh();

        $from = Carbon::now()->subDay();
        $to = Carbon::now()->addDay();

        $stats = new GetOrderCommissionStatsAction(
            app: $app,
            company: null,
            from: $from,
            to: $to,
            orderType: null,
        )->execute();

        $expectedTotalRevenue = (float) $order1->total_net_amount + (float) $order2->total_net_amount;
        $expectedTotalCommission = (float) $order1->commission_amount + (float) $order2->commission_amount;
        $expectedTotalProvider = (float) $order1->provider_amount + (float) $order2->provider_amount;

        $this->assertGreaterThanOrEqual($expectedTotalRevenue, $stats->total_revenue);
        $this->assertGreaterThanOrEqual($expectedTotalCommission, $stats->total_commission);
        $this->assertGreaterThanOrEqual($expectedTotalProvider, $stats->total_provider_amount);
        $this->assertGreaterThanOrEqual(2, $stats->order_count);
    }

    public function testCommissionStatsFilterByCompany(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        $order = $this->createMovipassOrder($app, [
            CommissionEnum::COMMISSION_RATE->value => '10',
        ]);
        new CalculateOrderCommissionAction($order)->execute();
        $order->refresh();

        $from = Carbon::now()->subDay();
        $to = Carbon::now()->addDay();

        $statsWithCompany = new GetOrderCommissionStatsAction(
            app: $app,
            company: $company,
            from: $from,
            to: $to,
            orderType: null,
        )->execute();

        $statsAllCompanies = new GetOrderCommissionStatsAction(
            app: $app,
            company: null,
            from: $from,
            to: $to,
            orderType: null,
        )->execute();

        $this->assertGreaterThanOrEqual(1, $statsWithCompany->order_count);
        $this->assertGreaterThanOrEqual($statsWithCompany->order_count, $statsAllCompanies->order_count);
        $this->assertGreaterThanOrEqual($statsWithCompany->total_commission, $statsAllCompanies->total_commission);
    }

    public function testCommissionStatsFilterByOrderType(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app, [
            CommissionEnum::COMMISSION_RATE->value => '10',
        ]);
        new CalculateOrderCommissionAction($order)->execute();

        $from = Carbon::now()->subDay();
        $to = Carbon::now()->addDay();

        $statsMovipass = new GetOrderCommissionStatsAction(
            app: $app,
            company: null,
            from: $from,
            to: $to,
            orderType: OrderTypeEnum::MOVIPASS->value,
        )->execute();

        $statsImpound = new GetOrderCommissionStatsAction(
            app: $app,
            company: null,
            from: $from,
            to: $to,
            orderType: OrderTypeEnum::IMPOUND_LOT->value,
        )->execute();

        $this->assertGreaterThanOrEqual(1, $statsMovipass->order_count);
        $this->assertLessThanOrEqual($statsMovipass->order_count, $statsImpound->order_count);
    }

    public function testLegacyOrdersReturnNullCommission(): void
    {
        $app = app(Apps::class);

        $order = $this->createMovipassOrder($app);

        $this->assertNull($order->commission_rate);
        $this->assertNull($order->commission_amount);
        $this->assertNull($order->provider_amount);
    }
}
