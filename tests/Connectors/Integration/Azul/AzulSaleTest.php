<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Azul\Enums\CustomFieldEnum;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Payments\Models\PaymentMethods; // used for lookup + typed property
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Users\Models\Users;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;

class AzulSaleTest extends AzulBase
{
    use InventoryCases;
    use PaymentCases;

    protected $company;
    protected $user;
    protected $apps;
    protected $region;
    protected $warehouseResponse;
    protected $channelResponse;
    protected string $variantId;
    protected PaymentMethods $azulPaymentMethod;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        // $this->apps = Apps::find(128); // Force reload to clear any cached config from other tests

        $existingMethod = PaymentMethods::fromApp($this->apps)
            ->where('processor', 'azul')
            ->where('is_deleted', 0)
            ->whereNotNull('stripe_card_id')
            ->where('stripe_card_id', '!=', '')
            ->latest()
            ->first();

        if ($existingMethod) {
            $this->user = Users::find($existingMethod->users_id);
            $this->actingAs($this->user, 'api');
        } else {
            $this->user = auth()->user();
        }

        $this->company = $this->user->getCurrentCompany();
        $this->region  = Regions::getDefault($this->company, $this->apps);

        $this->apps->set(ConfigurationEnum::AZUL_AUTH1->value, env('TEST_AZUL_AUTH1') ?? env('AZUL_AUTH1'));
        $this->apps->set(ConfigurationEnum::AZUL_AUTH2->value, env('TEST_AZUL_AUTH2') ?? env('AZUL_AUTH2'));
        $this->apps->set(ConfigurationEnum::AZUL_STORE->value, env('TEST_AZUL_STORE') ?? env('AZUL_STORE'));
        $this->apps->set(ConfigurationEnum::AZUL_CHANNEL->value, env('TEST_AZUL_CHANNEL') ?? env('AZUL_CHANNEL', 'EC'));
        $this->apps->set(ConfigurationEnum::AZUL_CERT_PATH->value, env('TEST_AZUL_CERT_PATH') ?? env('AZUL_CERT_PATH'));
        $this->apps->set(ConfigurationEnum::AZUL_KEY_PATH->value, env('TEST_AZUL_KEY_PATH') ?? env('AZUL_KEY_PATH'));
        $this->apps->set(ConfigurationEnum::AZUL_3DS_TERM_URL->value, env('TEST_AZUL_3DS_TERM_URL') ?? env('AZUL_3DS_TERM_URL'));
        $this->apps->set(ConfigurationEnum::AZUL_3DS_METHOD_NOTIFICATION_URL->value, env('TEST_AZUL_3DS_METHOD_NOTIFICATION_URL') ?? env('AZUL_3DS_METHOD_NOTIFICATION_URL'));

        $this->setAllowNoPaymentStatus(true, $this->apps);

        $this->warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $this->channelResponse   = $this->createChannel()->json()['data']['createChannel'];

        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $variant = Products::find($productResponse['id'])->variants()->first();

        $this->addVariantToChannel(
            variantId: (string) $variant->id,
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: (string) $variant->id,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->variantId = (string) $variant->id;

        // Always tokenize fresh — DataVault tokens from previous runs may be stale.
        $this->azulPaymentMethod = PaymentMethods::find(
            $this->addPaymentMethod($this->company, $this->getAzulCardData())['id']
        );
    }

    protected function getAzulCardData(): array
    {
        return [
            'number'          => '5424180279791732',
            'expiration_date' => '12/28',
            'cvv'             => '732',
            'processor'       => 'azul',
        ];
    }

    protected function createOrderFromCart(string $variantId, int $quantity = 1, array $metadata = []): Order
    {
        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order { id }
                }
            }
        ', [
            'input' => [
                'cartId'           => 0,
                'metadata'         => $metadata,
                'customer'         => ['email' => fake()->email()],
                'currency'         => 'USD',
                'shipping_address' => [
                    'address'   => fake()->address(),
                    'address_2' => fake()->postcode(),
                    'city'      => fake()->city(),
                    'state'     => fake()->state(),
                ],
                'items'      => [['variant_id' => $variantId, 'quantity' => $quantity]],
                'order_type' => 'order',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        return Order::find($response->json('data.createOrderFromCart.order.id'));
    }

    public function testProcessPaymentWithAzul(): void
    {
        $order = $this->createOrderFromCart(variantId: $this->variantId);

        $addResponse = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    message
                    payment {
                        id
                        status
                    }
                }
            }
        ', [
            'orderID' => $order->id,
            'input'   => [
                'payment_methods_id' => $this->azulPaymentMethod->id,
                'payment_method'     => 'CARD',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $addResponse->assertSuccessful();
        $this->assertNull($addResponse->json('errors'));
        $paymentId = $addResponse->json('data.addPaymentToOrder.payment.id');
        $this->assertNotEmpty($paymentId);

        $processResponse = $this->graphQL('
            mutation processPayment($paymentId: ID!) {
                processPayment(paymentId: $paymentId) {
                    status
                    message
                    payment {
                        id
                        status
                    }
                }
            }
        ', [
            'paymentId' => $paymentId,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $processResponse->assertSuccessful();
        $this->assertNull($processResponse->json('errors'));

        $data = $processResponse->json('data.processPayment');
        $this->assertEquals('success', $data['status'], 'Azul sale failed: ' . $data['message']);
        $this->assertEquals(PaymentStatusEnum::PAID->value, $data['payment']['status']);

        $order->refresh();
        $this->assertNotEmpty(
            $order->get(CustomFieldEnum::AZUL_ORDER_ID->value),
            'Azul order ID should be stored on the order after successful sale'
        );
    }
}
