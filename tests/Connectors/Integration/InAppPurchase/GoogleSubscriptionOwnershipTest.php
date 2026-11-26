<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Imdhemy\GooglePlay\Domain\Purchase\Entity\SubscriptionPurchase;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromGoogleReceiptAction;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromReceiptActionBase;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\GooglePlayInAppPurchaseReceipt;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Laravel\Cashier\Subscription as CashierSubscription;
use Tests\Connectors\Traits\MakesPlaySubscriptions;
use Tests\TestCase;

class GoogleSubscriptionOwnershipTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlaySubscriptions;

    // Same leak as AppleSignedTransactionTest: a purchase files the process's shared user as a CRM person.
    protected $connectionsToTransact = [null, 'crm', 'commerce'];

    public function testPurchaseTokenOfAnotherUserIsNotTakenOver(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();
        $region = Regions::fromApp($app)->fromCompany($company)->firstOrFail();

        $sku = 'google_owned_' . uniqid();
        $purchaseToken = 'token_' . Str::random(20);
        $orderId = 'GPA.' . uniqid();
        $this->createProduct($app, $sku);

        $otherCustomer = AppsStripeCustomer::create([
            'apps_id' => $app->getId(),
            'companies_id' => 0,
            'users_id' => $this->createUser()->getId(),
            'provider' => 'google_play',
            'stripe_id' => $purchaseToken,
        ]);
        CashierSubscription::create([
            'apps_stripe_customer_id' => $otherCustomer->getId(),
            'type' => 'google_play:' . $sku,
            'stripe_id' => $purchaseToken,
            'stripe_price' => $sku,
            'stripe_status' => 'active',
            'quantity' => 1,
            'ends_at' => now()->addMonth(),
        ]);

        $action = $this->mockedRenewableAction(
            new GooglePlayInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: $region,
                product_id: $sku,
                order_id: $orderId,
                purchase_token: $purchaseToken,
                is_renewable_subscription: true
            ),
            $this->playSubscription($sku, ['latestSuccessfulOrderId' => $orderId])
        );

        try {
            $action->execute();
            $this->fail('Another account\'s purchase token was accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('belongs to another account', $e->getMessage());
        }

        $subscription = CashierSubscription::where('stripe_id', $purchaseToken)->firstOrFail();
        $this->assertEquals($otherCustomer->getId(), $subscription->apps_stripe_customer_id);
        $this->assertEquals(0, ModelsOrder::fromApp($app)
            ->where('users_id', $user->getId())
            ->whereJsonContains('metadata->google_subscription->order_id', $orderId)
            ->count());
    }

    public function testResubmittingRebuildsAMissingSubscription(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $sku = 'google_repair_' . uniqid();
        $purchaseToken = 'token_' . Str::random(20);
        $orderId = 'GPA.' . uniqid();
        $this->createProduct($app, $sku);

        $receipt = new GooglePlayInAppPurchaseReceipt(
            app: $app,
            company: $company,
            user: $user,
            region: Regions::fromApp($app)->fromCompany($company)->firstOrFail(),
            product_id: $sku,
            order_id: $orderId,
            purchase_token: $purchaseToken,
            is_renewable_subscription: true
        );
        $purchase = $this->playSubscription($sku, ['latestSuccessfulOrderId' => $orderId]);

        $first = $this->mockedRenewableAction($receipt, $purchase)->execute();
        CashierSubscription::where('stripe_id', $purchaseToken)->delete();

        $second = $this->mockedRenewableAction($receipt, $purchase)->execute();

        $this->assertEquals($first->getId(), $second->getId());
        $subscription = CashierSubscription::where('stripe_id', $purchaseToken)->firstOrFail();
        $this->assertEquals('google_play:' . $sku, $subscription->type);
        $this->assertTrue($subscription->active());
        $this->assertEquals(1, ModelsOrder::fromApp($app)
            ->where('users_id', $user->getId())
            ->whereJsonContains('metadata->google_subscription->order_id', $orderId)
            ->count());
    }

    /**
     * subscriptions.stripe_id is unique across apps, so the row cannot be shared: the second app gets a clear
     * refusal instead of a unique-index error on insert.
     */
    public function testPurchaseTokenOwnedInAnotherAppIsRefused(): void
    {
        [$receipt, $purchase] = $this->renewablePurchase();
        $otherCustomer = AppsStripeCustomer::create([
            'apps_id' => $receipt->app->getId() + 1000000,
            'companies_id' => 0,
            'users_id' => $receipt->user->getId(),
            'provider' => 'google_play',
            'stripe_id' => $receipt->purchase_token,
        ]);
        CashierSubscription::create([
            'apps_stripe_customer_id' => $otherCustomer->getId(),
            'type' => 'google_play:' . $receipt->product_id,
            'stripe_id' => $receipt->purchase_token,
            'stripe_price' => $receipt->product_id,
            'stripe_status' => 'active',
            'quantity' => 1,
            'ends_at' => now()->addMonth(),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('belongs to another account');

        $this->mockedRenewableAction($receipt, $purchase)->execute();
    }

    public function testSubmissionWaitsWhileTheSamePurchaseIsBeingRecorded(): void
    {
        [$receipt, $purchase] = $this->renewablePurchase();
        $lockName = CreateOrderFromReceiptActionBase::storePurchaseLockName($receipt->purchase_token);
        $concurrentRequest = Cache::lock($lockName, 60);
        $this->assertTrue($concurrentRequest->get());

        try {
            $this->mockedRenewableAction($receipt, $purchase, waitForLock: false)->execute();
            $this->fail('The purchase was recorded while another request held its lock');
        } catch (LockTimeoutException) {
            $this->assertEquals(0, $this->countOrders($receipt));
            $this->assertNull(CashierSubscription::where('stripe_id', $receipt->purchase_token)->first());
        } finally {
            $concurrentRequest->release();
        }

        $first = $this->mockedRenewableAction($receipt, $purchase)->execute();
        $second = $this->mockedRenewableAction($receipt, $purchase)->execute();

        $this->assertEquals($first->getId(), $second->getId());
        $this->assertEquals(1, $this->countOrders($receipt));
    }

    public function testOrderIsCreatedUnderTheStorePurchaseLock(): void
    {
        [$receipt, $purchase] = $this->renewablePurchase();
        $lockName = CreateOrderFromReceiptActionBase::storePurchaseLockName($receipt->purchase_token);
        $lockWasFree = null;

        $this->mockedRenewableAction(
            $receipt,
            $purchase,
            whileCreating: function () use ($lockName, &$lockWasFree): void {
                $probe = Cache::lock($lockName, 1);
                $lockWasFree = $probe->get();
                if ($lockWasFree) {
                    $probe->release();
                }
            }
        )->execute();

        $this->assertFalse($lockWasFree);
        $this->assertTrue(Cache::lock($lockName, 1)->get());
    }

    /**
     * @return array{0: GooglePlayInAppPurchaseReceipt, 1: SubscriptionPurchase}
     */
    private function renewablePurchase(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $sku = 'google_lock_' . uniqid();
        $orderId = 'GPA.' . uniqid();
        $this->createProduct($app, $sku);

        return [
            new GooglePlayInAppPurchaseReceipt(
                app: $app,
                company: $company,
                user: $user,
                region: Regions::fromApp($app)->fromCompany($company)->firstOrFail(),
                product_id: $sku,
                order_id: $orderId,
                purchase_token: 'token_' . Str::random(20),
                is_renewable_subscription: true
            ),
            $this->playSubscription($sku, ['latestSuccessfulOrderId' => $orderId]),
        ];
    }

    private function countOrders(GooglePlayInAppPurchaseReceipt $receipt): int
    {
        return ModelsOrder::fromApp($receipt->app)
            ->where('users_id', $receipt->user->getId())
            ->whereJsonContains('metadata->google_subscription->order_id', $receipt->order_id)
            ->count();
    }

    private function createProduct(Apps $app, string $sku): void
    {
        $user = auth()->user();

        new CreateProductAction(
            new Product(
                app: $app,
                company: $user->getCurrentCompany(),
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
        GooglePlayInAppPurchaseReceipt $receipt,
        SubscriptionPurchase $response,
        bool $waitForLock = true,
        ?Closure $whileCreating = null
    ): CreateOrderFromGoogleReceiptAction {
        return new class (
            $receipt,
            $response,
            $waitForLock,
            $whileCreating
        ) extends CreateOrderFromGoogleReceiptAction {
            public function __construct(
                GooglePlayInAppPurchaseReceipt $googlePlayInAppPurchase,
                private readonly SubscriptionPurchase $response,
                private readonly bool $waitForLock,
                private readonly ?Closure $whileCreating
            ) {
                parent::__construct($googlePlayInAppPurchase);
            }

            protected function verifyRenewableReceipt(array $receipt): SubscriptionPurchase
            {
                return $this->response;
            }

            protected function purchaseLockWaitSeconds(): int
            {
                return $this->waitForLock ? parent::purchaseLockWaitSeconds() : 0;
            }

            protected function handleCustomFieldsOnOrder(ModelsOrder $order): void
            {
                parent::handleCustomFieldsOnOrder($order);
                $this->whileCreating?->__invoke();
            }
        };
    }
}
