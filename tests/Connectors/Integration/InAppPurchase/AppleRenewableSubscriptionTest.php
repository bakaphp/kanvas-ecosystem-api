<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Imdhemy\AppStore\Receipts\ReceiptResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromAppleReceiptAction;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\AppleInAppPurchaseReceipt;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription as CashierSubscription;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AppleRenewableSubscriptionTest extends TestCase
{
    use DatabaseTransactions;

    // Same leak as AppleSignedTransactionTest: a purchase files the process's shared user as a CRM person.
    protected $connectionsToTransact = [null, 'crm', 'commerce'];

    private string $runId;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('InAppPurchase integration tests are skipped in CI');
        }

        $this->runId = uniqid();
    }

    public function testCreateRenewableSubscriptionOrderAndPersistSubscription(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'sub_monthly_001');

        $response = ReceiptResponse::fromArray([
            'status' => 0,
            'environment' => 'Sandbox',
            'latest_receipt_info' => [[
                'original_transaction_id' => $this->originalTransactionId(1),
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
                original_transaction_id: $this->originalTransactionId(1)
            ),
            $response
        );

        $order = $action->execute();

        $this->assertEquals('renew_tx_1', $order->metadata['apple_subscription']['transaction_id']);
        $this->assertEquals(
            $this->originalTransactionId(1),
            $order->metadata['apple_subscription']['original_transaction_id']
        );
        $this->assertTrue($order->hasTag(['iap-subscription']));

        $appsStripeCustomer = AppsStripeCustomer::where('apps_id', $app->getId())
            ->where('users_id', $user->getId())
            ->where('companies_id', 0)
            ->where('provider', 'apple')
            ->first();

        $this->assertNotNull($appsStripeCustomer);
        $this->assertEquals($this->originalTransactionId(1), $appsStripeCustomer->stripe_id);
        $this->assertEquals($order->getId(), (int) ($appsStripeCustomer->config['order_id'] ?? 0));

        $subscription = CashierSubscription::where('apps_stripe_customer_id', $appsStripeCustomer->getId())
            ->where('stripe_id', $this->originalTransactionId(1))
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
        new Setup($app, $user, $company)->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'sub_monthly_002');

        $response = ReceiptResponse::fromArray([
            'status' => 0,
            'environment' => 'Sandbox',
            'latest_receipt_info' => [[
                'original_transaction_id' => $this->originalTransactionId(2),
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
            original_transaction_id: $this->originalTransactionId(2)
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
        new Setup($app, $user, $company)->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct($app, $company, $user, 'sub_monthly_003');

        $firstResponse = ReceiptResponse::fromArray([
            'status' => 0,
            'environment' => 'Sandbox',
            'latest_receipt_info' => [[
                'original_transaction_id' => $this->originalTransactionId(3),
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
                'original_transaction_id' => $this->originalTransactionId(3),
                'product_id' => 'sub_monthly_003',
                'quantity' => 1,
                'transaction_id' => 'renew_tx_3_a',
                'purchase_date_ms' => now()->subDays(2)->getTimestampMs(),
                'expires_date_ms' => now()->addMonth()->getTimestampMs(),
                'is_trial_period' => 'false',
                'is_in_intro_offer_period' => 'false',
            ], [
                'original_transaction_id' => $this->originalTransactionId(3),
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
                original_transaction_id: $this->originalTransactionId(3)
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
                original_transaction_id: $this->originalTransactionId(3)
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
            ->where('stripe_id', $this->originalTransactionId(3))
            ->firstOrFail();

        $this->assertEquals('sub_monthly_003', $subscription->stripe_price);
        $this->assertTrue(Carbon::parse((string) $subscription->ends_at)->isAfter(now()->addMonth()));
    }

    public function testRefundedReceiptEndsAccessAtTheCancellationDate(): void
    {
        $cancelledAt = now()->subHour();

        $this->executeRenewable(5, 'refund_tx_5', [
            $this->receiptInfo(
                lineage: 5,
                transactionId: 'refund_tx_5',
                purchasedAt: now()->subDays(2),
                expiresAt: now()->addMonth()
            )
                + ['cancellation_date_ms' => $cancelledAt->getTimestampMs()],
        ]);

        $subscription = $this->findSubscription(5);
        $this->assertEquals('canceled', $subscription->stripe_status);
        $this->assertEquals($cancelledAt->getTimestamp(), $subscription->ends_at->getTimestamp());
        $this->assertNull($subscription->trial_ends_at);
        $this->assertFalse($subscription->active());
        $this->assertFalse($subscription->valid());
    }

    public function testOnlyAPurchaseAfterTheRefundReactivatesTheSubscription(): void
    {
        $refunded = $this->receiptInfo(
            lineage: 6,
            transactionId: 'refund_tx_6_a',
            purchasedAt: now()->subDays(3),
            expiresAt: now()->addMonth()
        )
            + ['cancellation_date_ms' => now()->subDay()->getTimestampMs()];

        $refundedOrder = $this->executeRenewable(6, 'refund_tx_6_a', [$refunded]);

        $boughtBeforeRefund = $this->receiptInfo(
            lineage: 6,
            transactionId: 'refund_tx_6_b',
            purchasedAt: now()->subDays(2),
            expiresAt: now()->addMonths(2)
        );
        $replayed = $this->executeRenewable(6, 'refund_tx_6_b', [$refunded, $boughtBeforeRefund]);

        $this->assertEquals($refundedOrder->getId(), $replayed->getId());
        $this->assertEquals('canceled', $this->findSubscription(6)->stripe_status);

        $boughtAfterRefund = $this->receiptInfo(
            lineage: 6,
            transactionId: 'refund_tx_6_c',
            purchasedAt: now()->subMinute(),
            expiresAt: now()->addMonth()
        );
        $resubscribed = $this->executeRenewable(6, 'refund_tx_6_c', [$refunded, $boughtAfterRefund]);

        $this->assertNotEquals($refundedOrder->getId(), $resubscribed->getId());
        $subscription = $this->findSubscription(6);
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertTrue($subscription->active());
    }

    public function testReceiptTransactionWithoutExpiryIsRefused(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $this->createProduct(
            app: $app,
            company: $company,
            user: $user,
            sku: 'sub_monthly_005'
        );

        $response = ReceiptResponse::fromArray([
            'status' => 0,
            'environment' => 'Sandbox',
            'latest_receipt_info' => [[
                'original_transaction_id' => $this->originalTransactionId(5),
                'product_id' => 'sub_monthly_005',
                'quantity' => 1,
                'transaction_id' => 'renew_tx_5',
                'purchase_date_ms' => now()->subDay()->getTimestampMs(),
            ]],
        ]);

        $action = $this->mockedRenewableAction(
            new AppleInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: $region,
                product_id: 'sub_monthly_005',
                transaction_id: 'renew_tx_5',
                receipt: 'renewable-receipt',
                transaction_date: now()->getTimestampMs(),
                is_renewable_subscription: true,
                original_transaction_id: $this->originalTransactionId(5)
            ),
            $response
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has no expiry');

        $action->execute();
    }

    #[Group('serial')]
    public function testRenewableReceiptOfAnotherAppIsRejected(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::APPLE_BUNDLE_ID->value, 'com.kanvas.receipt.test');

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('Apple payload belongs to another app');

            $this->executeRenewable(
                lineage: 6,
                transactionId: 'renew_tx_6',
                latestReceiptInfo: [
                    $this->receiptInfo(
                        lineage: 6,
                        transactionId: 'renew_tx_6',
                        purchasedAt: now()->subDay(),
                        expiresAt: now()->addMonth()
                    ),
                ],
                receiptBundleId: 'com.someone.else'
            );
        } finally {
            $app->del(ConfigurationEnum::APPLE_BUNDLE_ID->value);
        }
    }

    public function testRenewableInvalidReceiptThrowsValidationException(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();
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
                original_transaction_id: $this->originalTransactionId(4)
            ),
            $invalidResponse
        )->execute();
    }

    /**
     * @param list<array<string, mixed>> $latestReceiptInfo
     */
    private function executeRenewable(
        int $lineage,
        string $transactionId,
        array $latestReceiptInfo,
        ?string $receiptBundleId = null
    ): Order {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        if (! Variants::query()->where('sku', $this->lineageSku($lineage))->exists()) {
            $this->createProduct(
                app: $app,
                company: $company,
                user: $user,
                sku: $this->lineageSku($lineage)
            );
        }

        return $this->mockedRenewableAction(
            new AppleInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: Regions::fromApp($app)->fromCompany($company)->firstOrFail(),
                product_id: $this->lineageSku($lineage),
                transaction_id: $transactionId,
                receipt: 'renewable-receipt',
                transaction_date: now()->getTimestampMs(),
                is_renewable_subscription: true,
                original_transaction_id: $this->originalTransactionId($lineage)
            ),
            ReceiptResponse::fromArray([
                'status' => 0,
                'environment' => 'Sandbox',
                'receipt' => ['bundle_id' => $receiptBundleId],
                'latest_receipt_info' => $latestReceiptInfo,
            ])
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptInfo(
        int $lineage,
        string $transactionId,
        Carbon $purchasedAt,
        Carbon $expiresAt
    ): array {
        return [
            'original_transaction_id' => $this->originalTransactionId($lineage),
            'product_id' => $this->lineageSku($lineage),
            'quantity' => 1,
            'transaction_id' => $transactionId,
            'purchase_date_ms' => $purchasedAt->getTimestampMs(),
            'expires_date_ms' => $expiresAt->getTimestampMs(),
            'is_trial_period' => 'false',
            'is_in_intro_offer_period' => 'false',
        ];
    }

    private function lineageSku(int $lineage): string
    {
        return 'sub_lineage_' . $lineage . '_' . $this->runId;
    }

    private function findSubscription(int $lineage): CashierSubscription
    {
        return CashierSubscription::where('stripe_id', $this->originalTransactionId($lineage))->firstOrFail();
    }

    private function createProduct(Apps $app, mixed $company, mixed $user, string $sku): void
    {
        new CreateProductAction(
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
        )->execute();
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

    private function originalTransactionId(int $number): string
    {
        return 'original_tx_' . $number . '_' . $this->runId;
    }
}
