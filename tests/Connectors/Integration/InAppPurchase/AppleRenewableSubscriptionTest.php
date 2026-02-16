<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Carbon\Carbon;
use Imdhemy\AppStore\Receipts\ReceiptResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromAppleReceiptAction;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\AppleInAppPurchaseReceipt;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription as CashierSubscription;
use Tests\TestCase;

class AppleRenewableSubscriptionTest extends TestCase
{
    public function testCreateRenewableSubscriptionOrderAndPersistSubscription(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        (new Setup($app, $user, $company))->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'sub_monthly_001');

        $response = ReceiptResponse::fromArray([
            'status' => 0,
            'environment' => 'Sandbox',
            'latest_receipt_info' => [[
                'original_transaction_id' => 'original_tx_1',
                'product_id' => 'sub_monthly_001',
                'quantity' => 1,
                'transaction_id' => 'renew_tx_1',
                'purchase_date_ms' => now()->subDay()->getTimestampMs(),
                'expires_date_ms' => now()->addMonth()->getTimestampMs(),
                'is_trial_period' => 'false',
                'is_in_intro_offer_period' => 'false',
            ]],
        ]);

        $action = $this->mockedRenewableAction(
            new AppleInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: $region,
                product_id: 'sub_monthly_001',
                transaction_id: 'renew_tx_1',
                receipt: 'renewable-receipt',
                transaction_date: now()->getTimestampMs(),
                is_renewable_subscription: true,
                original_transaction_id: 'original_tx_1'
            ),
            $response
        );

        $order = $action->execute();

        $this->assertEquals('renew_tx_1', $order->metadata['apple_subscription']['transaction_id']);
        $this->assertEquals('original_tx_1', $order->metadata['apple_subscription']['original_transaction_id']);
        $this->assertTrue($order->hasTag(['iap-subscription']));

        $appsStripeCustomer = AppsStripeCustomer::where('apps_id', $app->getId())
            ->where('users_id', $user->getId())
            ->where('companies_id', 0)
            ->where('provider', 'apple')
            ->first();

        $this->assertNotNull($appsStripeCustomer);
        $this->assertEquals('original_tx_1', $appsStripeCustomer->stripe_id);

        $subscription = CashierSubscription::where('apps_stripe_customer_id', $appsStripeCustomer->getId())
            ->where('stripe_id', 'original_tx_1')
            ->first();

        $this->assertNotNull($subscription);
        $this->assertEquals('apple:sub_monthly_001', $subscription->type);
        $this->assertEquals('sub_monthly_001', $subscription->stripe_price);
        $this->assertEquals('active', $subscription->stripe_status);
    }

    public function testRenewableOrderIsIdempotentByTransactionId(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        (new Setup($app, $user, $company))->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'sub_monthly_002');

        $response = ReceiptResponse::fromArray([
            'status' => 0,
            'environment' => 'Sandbox',
            'latest_receipt_info' => [[
                'original_transaction_id' => 'original_tx_2',
                'product_id' => 'sub_monthly_002',
                'quantity' => 1,
                'transaction_id' => 'renew_tx_2',
                'purchase_date_ms' => now()->subDay()->getTimestampMs(),
                'expires_date_ms' => now()->addMonth()->getTimestampMs(),
                'is_trial_period' => 'false',
                'is_in_intro_offer_period' => 'false',
            ]],
        ]);

        $dto = new AppleInAppPurchaseReceipt(
            app: $app,
            company: $company,
            user: $user,
            region: $region,
            product_id: 'sub_monthly_002',
            transaction_id: 'renew_tx_2',
            receipt: 'renewable-receipt',
            transaction_date: now()->getTimestampMs(),
            is_renewable_subscription: true,
            original_transaction_id: 'original_tx_2'
        );

        $orderA = $this->mockedRenewableAction($dto, $response)->execute();
        $orderB = $this->mockedRenewableAction($dto, $response)->execute();

        $this->assertEquals($orderA->getId(), $orderB->getId());

        $ordersCount = Order::fromApp($app)
            ->where('users_id', $user->getId())
            ->whereJsonContains('metadata->apple_subscription->transaction_id', 'renew_tx_2')
            ->count();

        $this->assertEquals(1, $ordersCount);
    }

    public function testRenewalCreatesNewOrderAndUpdatesExistingSubscription(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        (new Setup($app, $user, $company))->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'sub_monthly_003');

        $firstResponse = ReceiptResponse::fromArray([
            'status' => 0,
            'environment' => 'Sandbox',
            'latest_receipt_info' => [[
                'original_transaction_id' => 'original_tx_3',
                'product_id' => 'sub_monthly_003',
                'quantity' => 1,
                'transaction_id' => 'renew_tx_3_a',
                'purchase_date_ms' => now()->subDays(2)->getTimestampMs(),
                'expires_date_ms' => now()->addMonth()->getTimestampMs(),
                'is_trial_period' => 'false',
                'is_in_intro_offer_period' => 'false',
            ]],
        ]);

        $secondResponse = ReceiptResponse::fromArray([
            'status' => 0,
            'environment' => 'Sandbox',
            'latest_receipt_info' => [[
                'original_transaction_id' => 'original_tx_3',
                'product_id' => 'sub_monthly_003',
                'quantity' => 1,
                'transaction_id' => 'renew_tx_3_a',
                'purchase_date_ms' => now()->subDays(2)->getTimestampMs(),
                'expires_date_ms' => now()->addMonth()->getTimestampMs(),
                'is_trial_period' => 'false',
                'is_in_intro_offer_period' => 'false',
            ], [
                'original_transaction_id' => 'original_tx_3',
                'product_id' => 'sub_monthly_003',
                'quantity' => 1,
                'transaction_id' => 'renew_tx_3_b',
                'purchase_date_ms' => now()->subHour()->getTimestampMs(),
                'expires_date_ms' => now()->addMonths(2)->getTimestampMs(),
                'is_trial_period' => 'false',
                'is_in_intro_offer_period' => 'false',
            ]],
        ]);

        $firstOrder = $this->mockedRenewableAction(
            new AppleInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: $region,
                product_id: 'sub_monthly_003',
                transaction_id: 'renew_tx_3_a',
                receipt: 'renewable-receipt',
                transaction_date: now()->subDays(2)->getTimestampMs(),
                is_renewable_subscription: true,
                original_transaction_id: 'original_tx_3'
            ),
            $firstResponse
        )->execute();

        $secondOrder = $this->mockedRenewableAction(
            new AppleInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: $region,
                product_id: 'sub_monthly_003',
                transaction_id: 'renew_tx_3_b',
                receipt: 'renewable-receipt',
                transaction_date: now()->subHour()->getTimestampMs(),
                is_renewable_subscription: true,
                original_transaction_id: 'original_tx_3'
            ),
            $secondResponse
        )->execute();

        $this->assertNotEquals($firstOrder->getId(), $secondOrder->getId());
        $this->assertEquals('renew_tx_3_b', $secondOrder->metadata['apple_subscription']['transaction_id']);

        $appsStripeCustomer = AppsStripeCustomer::where('apps_id', $app->getId())
            ->where('users_id', $user->getId())
            ->where('companies_id', 0)
            ->where('provider', 'apple')
            ->firstOrFail();

        $subscription = CashierSubscription::where('apps_stripe_customer_id', $appsStripeCustomer->getId())
            ->where('stripe_id', 'original_tx_3')
            ->firstOrFail();

        $this->assertEquals('sub_monthly_003', $subscription->stripe_price);
        $this->assertTrue(Carbon::parse((string) $subscription->ends_at)->isAfter(now()->addMonth()));
    }

    public function testRenewableInvalidReceiptThrowsValidationException(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        (new Setup($app, $user, $company))->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'sub_monthly_004');

        $invalidResponse = ReceiptResponse::fromArray([
            'status' => 21010,
            'environment' => 'Sandbox',
            'latest_receipt_info' => [],
        ]);

        $this->expectException(ValidationException::class);

        $this->mockedRenewableAction(
            new AppleInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: $region,
                product_id: 'sub_monthly_004',
                transaction_id: 'renew_tx_4',
                receipt: 'renewable-receipt',
                transaction_date: now()->getTimestampMs(),
                is_renewable_subscription: true,
                original_transaction_id: 'original_tx_4'
            ),
            $invalidResponse
        )->execute();
    }

    private function createProduct(Apps $app, mixed $company, mixed $user, string $sku): void
    {
        (new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: $sku,
                sku: $sku,
                warehouses: [[
                    'quantity' => 10,
                    'price' => 9.99,
                ]]
            ),
            $user
        ))->execute();
    }

    private function mockedRenewableAction(
        AppleInAppPurchaseReceipt $receipt,
        ReceiptResponse $response
    ): CreateOrderFromAppleReceiptAction {
        return new class ($receipt, $response) extends CreateOrderFromAppleReceiptAction {
            public function __construct(
                AppleInAppPurchaseReceipt $appleInAppPurchase,
                private readonly ReceiptResponse $response
            ) {
                parent::__construct($appleInAppPurchase, runInSandbox: true);
            }

            protected function verifyRenewableReceipt(array $receipt): ReceiptResponse
            {
                return $this->response;
            }
        };
    }
}
