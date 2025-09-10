<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ProcessNetSuiteSalesOrderAction;
use Kanvas\Connectors\NetSuite\Actions\PushOrderToNetSuiteQuoteAction;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite;
use Kanvas\Connectors\NetSuite\Services\NetSuiteServices;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class OrderTest extends TestCase
{
    use InventoryCases;

    protected $variant;
    protected $region;
    protected $company;
    protected $user;
    protected $apps;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(
            data: [
                'name' => 'NetSuite Order Test Product',
                'description' => 'NetSuite Order Test Description',
                'sku' => '4511338005811',
                'slug' => '4511338005811',
                'weight' => 1,
                'price' => 100,
                //'quantity' => 100,
            ],
            attributes: [
                [
                    'name' => 'slots',
                    'value' => 100,
                ],
            ]
        )->json()['data']['createProduct'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $product = Products::find($productResponse['id']);
        $variant = $product->variants()->first();

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: (string) $variant->getId(),
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: (string) $variant->getId(),
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $this->variant = $variant;

        $data = new NetSuite(
            app: $this->apps,
            company: $this->company,
            account: env('NET_SUITE_ACCOUNT'),
            consumerKey: env('NET_SUITE_CONSUMER_KEY'),
            consumerSecret: env('NET_SUITE_CONSUMER_SECRET'),
            token: env('NET_SUITE_TOKEN'),
            tokenSecret: env('NET_SUITE_TOKEN_SECRET')
        );

        NetSuiteServices::setup($data);
    }

    public function createDraftOrder(): Order
    {
        $data = [
            'email' => fake()->email(),
            'region_id' => $this->region->getId(),
            'metadata' => hash('sha256', random_bytes(10)),
            'customer' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'items' => [
                [
                    'variant_id' => $this->variant->getId(),
                    'quantity' => 5,
                    'price' => 6,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $order = Order::find($response->json('data.createDraftOrder.id'));

        return $order;
    }

    public function testPushOrderWithExistingCustomer(): void
    {
        // Get the order you want to push
        $order = $this->createDraftOrder();

        // Create the action
        $pushAction = new PushOrderToNetSuiteQuoteAction($this->apps, $this->company);

        // Push order to NetSuite with an existing NetSuite customer ID
        $result = $pushAction->execute(
            order: $order,
            netsuiteCustomerId: getenv('NET_SUITE_CUSTOMER_ID'), // NetSuite customer internal ID
            createCustomerIfNotExists: false
        );

        if ($result['success']) {
            $this->assertNotNull($result['data']['netsuite_quote_id']);
            $this->assertNotNull($result['data']['netsuite_quote_number']);
        } else {
            $this->fail($result['message']);
        }
    }

    public function testPushOrderWithoutCustomer(): void
    {
        // Get the order you want to push
        $order = $this->createDraftOrder();

        // Create the action
        $pushAction = new PushOrderToNetSuiteQuoteAction($this->apps, $this->company);

        // Push order to NetSuite without specifying a customer
        $result = $pushAction->execute(
            order: $order,
            netsuiteCustomerId: null,
            createCustomerIfNotExists: false
        );

        if ($result['success']) {
            $this->assertNotNull($result['data']['netsuite_quote_id']);
            $this->assertNotNull($result['data']['netsuite_quote_number']);
        } else {
            $this->fail($result['message']);
        }
    }

    /*
    @todo These tests are currently commented out because the functionality is not implemented yet.
    public function updateExistingQuote(): void
        {
            // Get the order that was already pushed to NetSuite
            $order = $this->createDraftOrder();

            // Create the action
            $pushAction = new PushOrderToNetSuiteQuoteAction($this->apps, $this->company);

            // // Update the existing quote
            $result = $pushAction->updateQuote($order);

            if ($result['success']) {
                $this->assertNotNull($result['data']['netsuite_quote_id']);
                $this->assertNotNull($result['data']['netsuite_quote_number']);
            } else {
                $this->fail($result['message']);
            }
        }

        public function testConvertQuoteToSalesOrder(): void
        {
            // Get the order that has an associated NetSuite quote
            $order = $this->createDraftOrder();

            // Create the action
            $pushAction = new PushOrderToNetSuiteQuoteAction($this->apps, $this->company);

            // Convert the quote to a sales order
            $result = $pushAction->convertQuoteToSalesOrder($order);

            if ($result['success']) {
                $this->assertNotNull($result['data']['netsuite_sales_order_id']);
                $this->assertNotNull($result['data']['netsuite_sales_order_number']);
            } else {
                $this->fail($result['message']);
            }
        } */

    public function testSyncOrderStatus(): void
    {
        // Get the order that has an associated NetSuite quote
        $order = $this->createDraftOrder();

        // Create the action
        $pushAction = new PushOrderToNetSuiteQuoteAction($this->apps, $this->company);
        $result = $pushAction->execute(
            order: $order,
            netsuiteCustomerId: null,
            createCustomerIfNotExists: false
        );

        // Sync status from NetSuite
        $result = $pushAction->syncOrderStatusFromNetSuite($order);

        if ($result['success']) {
            $this->assertNotNull($result['data']['netsuite_quote_status']);
        } else {
            $this->fail($result['message']);
        }
    }

    public function testCheckOrderNetSuiteStatus(): void
    {
        $order = $this->createDraftOrder();

        $pushAction = new PushOrderToNetSuiteQuoteAction($this->apps, $this->company);
        $result = $pushAction->execute(
            order: $order,
            netsuiteCustomerId: null,
            createCustomerIfNotExists: false
        );

        $order->refresh();
        $netsuiteQuoteId = $order->getMetadata('netsuite_quote_id');
        $netsuiteQuoteNumber = $order->getMetadata('netsuite_quote_number');
        $netsuiteStatus = $order->getMetadata('netsuite_quote_status');
        $pushedAt = $order->getMetadata('netsuite_pushed_at');

        if ($netsuiteQuoteId) {
            $this->assertNotNull($netsuiteQuoteId);
            $this->assertNotNull($netsuiteQuoteNumber);
            $this->assertNotNull($netsuiteStatus);
            $this->assertNotNull($pushedAt);

            // Check if it's been converted to sales order
            $salesOrderId = $order->getMetadata('netsuite_sales_order_id');
            if ($salesOrderId) {
                $this->assertNotNull($salesOrderId);
                $this->assertNotNull($order->getMetadata('netsuite_sales_order_number'));
                $this->assertNotNull($order->getMetadata('netsuite_converted_at'));
            }
        } else {
            $this->fail('Order has not been pushed to NetSuite yet.');
        }
    }

    public function testSearchSalesOrderInformation()
    {
        $company = Companies::first();
        $app = app(Apps::class);
        $user = auth()->user();

        $salesOrderId = env('NET_SUITE_SALES_ORDER_ID');

        if (! $salesOrderId) {
            $this->markTestSkipped('NET_SUITE_SALES_ORDER_ID environment variable not set');
        }

        $processOrderAction = new ProcessNetSuiteSalesOrderAction($app, $company, $user);

        // Use reflection to access the private getSalesOrderById method
        $reflection = new \ReflectionClass($processOrderAction);
        $method = $reflection->getMethod('getSalesOrderById');
        $method->setAccessible(true);

        $salesOrder = $method->invoke($processOrderAction, $salesOrderId);

        $items = is_array($salesOrder->itemList->item)
            ? $salesOrder->itemList->item
            : [$salesOrder->itemList->item];

        $this->assertNotNull($salesOrder);
        $this->assertInstanceOf(\NetSuite\Classes\SalesOrder::class, $salesOrder);
        $this->assertNotNull($salesOrder->internalId ?? null);
    }

    public function testProcessSalesOrderAction()
    {
        $company = Companies::first();
        $app = app(Apps::class);
        $user = auth()->user();

        $salesOrderId = env('NET_SUITE_SALES_ORDER_ID');

        if (! $salesOrderId) {
            $this->markTestSkipped('NET_SUITE_SALES_ORDER_ID environment variable not set');
        }

        $result = new ProcessNetSuiteSalesOrderAction($app, $company, $user)->execute($salesOrderId, 5);

        $this->assertNotNull($result);
    }

    public function testProcessNetSuiteSalesOrderActionWithInvalidOrderId()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $user = auth()->user();

        $processOrderAction = new ProcessNetSuiteSalesOrderAction($app, $company, $user);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Error retrieving sales order|Sales order .* not found/');

        // Use an invalid order ID
        $processOrderAction->execute('99999999');
    }
}
