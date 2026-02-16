<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Imdhemy\GooglePlay\Subscriptions\SubscriptionPurchase;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromGoogleReceiptAction;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\GooglePlayInAppPurchaseReceipt;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription as CashierSubscription;
use Tests\TestCase;

class GoogleRenewableSubscriptionTest extends TestCase
{
    public function testCreateRenewableGoogleSubscriptionOrderAndPersistSubscription(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        (new Setup($app, $user, $company))->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'google_sub_monthly_001');
        $purchaseToken = 'token_' . Str::random(20);

        $subscriptionPurchase = SubscriptionPurchase::fromArray([
            'orderId' => 'GPA.1000-2000-3000-40000..0',
            'expiryTimeMillis' => now()->addMonth()->getTimestampMs(),
            'paymentState' => SubscriptionPurchase::PAYMENT_STATE_RECEIVED,
            'acknowledgementState' => SubscriptionPurchase::ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED,
            'autoRenewing' => true,
            'linkedPurchaseToken' => null,
        ]);

        $action = $this->mockedRenewableAction(
            new GooglePlayInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: $region,
                product_id: 'google_sub_monthly_001',
                order_id: 'GPA.1000-2000-3000-40000..0',
                purchase_token: $purchaseToken,
                is_renewable_subscription: true
            ),
            $subscriptionPurchase
        );

        $order = $action->execute();

        $this->assertEquals('GPA.1000-2000-3000-40000..0', $order->metadata['google_subscription']['order_id']);
        $this->assertEquals($purchaseToken, $order->metadata['google_subscription']['purchase_token']);
        $this->assertTrue($order->hasTag(['iap-subscription']));

        $appsStripeCustomer = AppsStripeCustomer::where('apps_id', $app->getId())
            ->where('users_id', $user->getId())
            ->where('companies_id', 0)
            ->where('provider', 'google_play')
            ->first();

        $this->assertNotNull($appsStripeCustomer);
        $this->assertEquals($purchaseToken, $appsStripeCustomer->stripe_id);

        $subscription = CashierSubscription::where('apps_stripe_customer_id', $appsStripeCustomer->getId())
            ->where('stripe_id', $purchaseToken)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertEquals('google_play:google_sub_monthly_001', $subscription->type);
        $this->assertEquals('google_sub_monthly_001', $subscription->stripe_price);
        $this->assertEquals('active', $subscription->stripe_status);
    }

    public function testGoogleRenewableOrderIsIdempotentByOrderId(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        (new Setup($app, $user, $company))->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'google_sub_monthly_002');
        $purchaseToken = 'token_' . Str::random(20);

        $subscriptionPurchase = SubscriptionPurchase::fromArray([
            'orderId' => 'GPA.1000-2000-3000-50000..0',
            'expiryTimeMillis' => now()->addMonth()->getTimestampMs(),
            'paymentState' => SubscriptionPurchase::PAYMENT_STATE_RECEIVED,
            'acknowledgementState' => SubscriptionPurchase::ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED,
            'autoRenewing' => true,
        ]);

        $dto = new GooglePlayInAppPurchaseReceipt(
            app: $app,
            company: $company,
            user: $user,
            region: $region,
            product_id: 'google_sub_monthly_002',
            order_id: 'GPA.1000-2000-3000-50000..0',
            purchase_token: $purchaseToken,
            is_renewable_subscription: true
        );

        $orderA = $this->mockedRenewableAction($dto, $subscriptionPurchase)->execute();
        $orderB = $this->mockedRenewableAction($dto, $subscriptionPurchase)->execute();

        $this->assertEquals($orderA->getId(), $orderB->getId());

        $ordersCount = Order::fromApp($app)
            ->where('users_id', $user->getId())
            ->whereJsonContains('metadata->google_subscription->order_id', 'GPA.1000-2000-3000-50000..0')
            ->count();

        $this->assertEquals(1, $ordersCount);
    }

    public function testGoogleRenewalCreatesNewOrderAndUpdatesExistingSubscription(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        (new Setup($app, $user, $company))->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'google_sub_monthly_003');
        $purchaseToken = 'token_' . Str::random(20);

        $firstPurchase = SubscriptionPurchase::fromArray([
            'orderId' => 'GPA.1000-2000-3000-60000..0',
            'expiryTimeMillis' => now()->addMonth()->getTimestampMs(),
            'paymentState' => SubscriptionPurchase::PAYMENT_STATE_RECEIVED,
            'acknowledgementState' => SubscriptionPurchase::ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED,
            'autoRenewing' => true,
        ]);

        $secondPurchase = SubscriptionPurchase::fromArray([
            'orderId' => 'GPA.1000-2000-3000-60000..1',
            'expiryTimeMillis' => now()->addMonths(2)->getTimestampMs(),
            'paymentState' => SubscriptionPurchase::PAYMENT_STATE_RECEIVED,
            'acknowledgementState' => SubscriptionPurchase::ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED,
            'autoRenewing' => true,
            'linkedPurchaseToken' => $purchaseToken,
        ]);

        $firstOrder = $this->mockedRenewableAction(
            new GooglePlayInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: $region,
                product_id: 'google_sub_monthly_003',
                order_id: 'GPA.1000-2000-3000-60000..0',
                purchase_token: $purchaseToken,
                is_renewable_subscription: true
            ),
            $firstPurchase
        )->execute();

        $secondOrder = $this->mockedRenewableAction(
            new GooglePlayInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: $region,
                product_id: 'google_sub_monthly_003',
                order_id: 'GPA.1000-2000-3000-60000..1',
                purchase_token: $purchaseToken,
                is_renewable_subscription: true
            ),
            $secondPurchase
        )->execute();

        $this->assertNotEquals($firstOrder->getId(), $secondOrder->getId());
        $this->assertEquals('GPA.1000-2000-3000-60000..1', $secondOrder->metadata['google_subscription']['order_id']);

        $appsStripeCustomer = AppsStripeCustomer::where('apps_id', $app->getId())
            ->where('users_id', $user->getId())
            ->where('companies_id', 0)
            ->where('provider', 'google_play')
            ->firstOrFail();

        $subscription = CashierSubscription::where('apps_stripe_customer_id', $appsStripeCustomer->getId())
            ->where('stripe_id', $purchaseToken)
            ->firstOrFail();

        $this->assertEquals('google_sub_monthly_003', $subscription->stripe_price);
        $this->assertTrue(Carbon::parse((string) $subscription->ends_at)->isAfter(now()->addMonth()));
    }

    public function testGoogleRenewableWithoutOrderIdThrowsValidationException(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        (new Setup($app, $user, $company))->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'google_sub_monthly_004');
        $purchaseToken = 'token_' . Str::random(20);

        $this->expectException(ValidationException::class);

        $this->mockedRenewableAction(
            new GooglePlayInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: $region,
                product_id: 'google_sub_monthly_004',
                order_id: '',
                purchase_token: $purchaseToken,
                is_renewable_subscription: true
            ),
            SubscriptionPurchase::fromArray([
                'expiryTimeMillis' => now()->addMonth()->getTimestampMs(),
                'paymentState' => SubscriptionPurchase::PAYMENT_STATE_RECEIVED,
                'acknowledgementState' => SubscriptionPurchase::ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED,
                'autoRenewing' => true,
            ])
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
        GooglePlayInAppPurchaseReceipt $receipt,
        SubscriptionPurchase $response
    ): CreateOrderFromGoogleReceiptAction {
        return new class ($receipt, $response) extends CreateOrderFromGoogleReceiptAction {
            public function __construct(
                GooglePlayInAppPurchaseReceipt $googlePlayInAppPurchase,
                private readonly SubscriptionPurchase $response
            ) {
                parent::__construct($googlePlayInAppPurchase);
            }

            protected function verifyRenewableReceipt(array $receipt): SubscriptionPurchase
            {
                return $this->response;
            }
        };
    }
}
