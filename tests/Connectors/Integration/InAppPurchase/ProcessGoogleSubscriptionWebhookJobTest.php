<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Baka\Contracts\AppInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Imdhemy\GooglePlay\Subscriptions\SubscriptionPurchase;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum as InAppPurchaseConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Jobs\ProcessGoogleSubscriptionWebhookJob;
use Kanvas\Connectors\InAppPurchase\Services\GooglePlayBillingService;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Laravel\Cashier\Subscription as CashierSubscription;
use RuntimeException;
use Tests\TestCase;

class ProcessGoogleSubscriptionWebhookJobTest extends TestCase
{
    private const array PLAY_SETTINGS = [
        ConfigurationEnum::GOOGLE_PAYMENT_CLIENT_CONFIG->value => ['type' => 'service_account'],
        InAppPurchaseConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value => 'com.test.app',
    ];

    private string $purchaseToken;
    private string $subscriptionId = 'com.test.premium.monthly';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchaseToken = 'google_test_token_' . uniqid();
        // The app under test may hold a real Play service account, which would send every
        // notification-path test to the Play API.
        $this->withoutPlayAccount();
    }

    public function testHandleTestNotification(): void
    {
        $payload = $this->buildGooglePayload(isTest: true);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('Test notification received', $result['message']);
    }

    public function testHandleMissingMessageData(): void
    {
        $result = $this->dispatchWebhookJob([]);

        $this->assertIsArray($result);
        $this->assertEquals('Missing message.data', $result['message']);
        $this->assertEquals('skipped', $result['status']);
    }

    public function testHandleSubscriptionCanceled(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildGooglePayload(notificationType: 3);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('Google Play subscription webhook processed', $result['message']);
        $this->assertEquals('canceled', $result['new_status']);
        $this->assertEquals('SUBSCRIPTION_CANCELED', $result['notification_name']);

        $subscription = $this->findSubscription();
        $this->assertEquals('canceled', $subscription->stripe_status);
    }

    public function testHandleSubscriptionRenewed(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildGooglePayload(notificationType: 2);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('active', $result['new_status']);
        $this->assertEquals('SUBSCRIPTION_RENEWED', $result['notification_name']);

        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
    }

    public function testHandleSubscriptionExpired(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildGooglePayload(notificationType: 13);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('expired', $result['new_status']);

        $subscription = $this->findSubscription();
        $this->assertEquals('expired', $subscription->stripe_status);
    }

    public function testHandleSubscriptionRevoked(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildGooglePayload(notificationType: 12);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('canceled', $result['new_status']);

        $subscription = $this->findSubscription();
        $this->assertEquals('canceled', $subscription->stripe_status);
        $this->assertFalse($subscription->active());
    }

    public function testRenewalTakesExpiryFromPlay(): void
    {
        $this->seedSubscription('active');
        $this->findSubscription()->forceFill(['ends_at' => now()->subDay()])->saveOrFail();
        $nextExpiry = now()->addMonth()->startOfSecond();
        $this->fakePlaySubscription([
            'expiryTimeMillis' => (string) $nextExpiry->getTimestampMs(),
            'paymentState' => 1,
        ]);

        $result = $this->dispatchWebhookJob($this->buildGooglePayload(notificationType: 2));

        $this->assertEquals('play_api', $result['source']);
        $this->assertEquals('active', $result['new_status']);
        $subscription = $this->findSubscription();
        $this->assertEquals($nextExpiry->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertTrue($subscription->active());
    }

    public function testForgedRevocationDoesNotEndAccessWhenPlaySaysActive(): void
    {
        $this->seedSubscription('active');
        $this->fakePlaySubscription([
            'expiryTimeMillis' => (string) now()->addMonth()->getTimestampMs(),
            'paymentState' => 1,
        ]);

        $result = $this->dispatchWebhookJob($this->buildGooglePayload(notificationType: 12));

        $this->assertEquals('active', $result['new_status']);
        $this->assertTrue($this->findSubscription()->active());
    }

    public function testRevocationConfirmedByPlayEndsAccess(): void
    {
        $this->seedSubscription('active');
        $revokedAt = now()->subMinute()->startOfSecond();
        $this->fakePlaySubscription([
            'expiryTimeMillis' => (string) $revokedAt->getTimestampMs(),
            'paymentState' => 1,
            'cancelReason' => 3,
        ]);

        $result = $this->dispatchWebhookJob($this->buildGooglePayload(notificationType: 12));

        $this->assertEquals('canceled', $result['new_status']);
        $subscription = $this->findSubscription();
        $this->assertEquals($revokedAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertFalse($subscription->active());
    }

    public function testRevokedClaimForAutoRenewTurnedOffKeepsThePaidPeriod(): void
    {
        $this->seedSubscription('active');
        $paidUntil = now()->addMonth()->startOfSecond();
        $this->fakePlaySubscription([
            'expiryTimeMillis' => (string) $paidUntil->getTimestampMs(),
            'paymentState' => 1,
            'autoRenewing' => false,
            'cancelReason' => 0,
            'userCancellationTimeMillis' => (string) now()->getTimestampMs(),
        ]);

        $result = $this->dispatchWebhookJob($this->buildGooglePayload(notificationType: 12));

        $this->assertEquals('canceled', $result['new_status']);
        $subscription = $this->findSubscription();
        $this->assertEquals($paidUntil->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertTrue($subscription->active());
    }

    public function testPlayStateWithoutExpiryLeavesSubscriptionUnchanged(): void
    {
        Exceptions::fake();
        $this->seedSubscription('active');
        $endsAt = $this->findSubscription()->ends_at;
        $this->fakePlaySubscription(['paymentState' => 1]);

        $result = $this->dispatchWebhookJob($this->buildGooglePayload(notificationType: 2));

        $this->assertNull($result);
        $storedEndsAt = $this->findSubscription()->ends_at;
        $this->assertEquals($endsAt->toDateTimeString(), $storedEndsAt->toDateTimeString());
    }

    public function testPlayFailureLeavesSubscriptionUnchanged(): void
    {
        Exceptions::fake();
        $this->seedSubscription('active');
        $endsAt = $this->findSubscription()->ends_at;
        $this->failPlaySubscription(new RuntimeException('Play Developer API unavailable'));

        $result = $this->dispatchWebhookJob($this->buildGooglePayload(notificationType: 3));

        $this->assertNull($result);
        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertEquals($endsAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        Exceptions::assertReported(
            fn (RuntimeException $e): bool => $e->getMessage() === 'Play Developer API unavailable'
        );
    }

    public function testForgedRevocationDuringPlayFailureDoesNotEndAccess(): void
    {
        Exceptions::fake();
        $this->seedSubscription('active');
        $this->failPlaySubscription(new RuntimeException('Play Developer API unavailable'));

        $result = $this->dispatchWebhookJob($this->buildGooglePayload(notificationType: 12));

        $this->assertNull($result);
        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertTrue($subscription->active());
    }

    public function testSignInClientConfigAloneKeepsNotificationPath(): void
    {
        $this->seedSubscription('active');
        $this->withoutPlayAccount([
            ConfigurationEnum::GOOGLE_CLIENT_CONFIG->value => ['type' => 'service_account'],
            InAppPurchaseConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value => 'com.test.app',
        ]);

        $result = $this->dispatchWebhookJob($this->buildGooglePayload(notificationType: 3));

        $this->assertEquals('notification', $result['source']);
        $this->assertEquals('canceled', $this->findSubscription()->stripe_status);
    }

    public function testPaymentServiceAccountRequiresDedicatedConfigAndPackageName(): void
    {
        $signInOnly = $this->billingFor([
            ConfigurationEnum::GOOGLE_CLIENT_CONFIG->value => ['type' => 'service_account'],
            InAppPurchaseConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value => 'com.test.app',
        ]);
        $withoutPackage = $this->billingFor([
            ConfigurationEnum::GOOGLE_PAYMENT_CLIENT_CONFIG->value => ['type' => 'service_account'],
        ]);

        $this->assertTrue($signInOnly->isConfigured());
        $this->assertFalse($signInOnly->hasPaymentServiceAccount());
        $this->assertFalse($withoutPackage->hasPaymentServiceAccount());
        $this->assertTrue($this->billingFor(self::PLAY_SETTINGS)->hasPaymentServiceAccount());
    }

    public function testUpdatesOlderLineageAfterCustomerMovedToNewerToken(): void
    {
        $olderToken = $this->purchaseToken;
        $this->seedSubscription('active');
        $this->purchaseToken = 'google_test_token_' . uniqid();
        $this->seedSubscription('active');
        $this->purchaseToken = $olderToken;

        $result = $this->dispatchWebhookJob($this->buildGooglePayload(notificationType: 3));

        $this->assertEquals('canceled', $result['new_status']);
        $this->assertEquals('canceled', $this->findSubscription($olderToken)->stripe_status);
    }

    public function testDoesNotTouchSubscriptionOfAnotherApp(): void
    {
        $this->seedSubscription('active', appsId: app(Apps::class)->getId() + 1000000);

        $result = $this->dispatchWebhookJob($this->buildGooglePayload(notificationType: 12));

        $this->assertEquals('No matching Google Play subscription found', $result['message']);
        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertTrue($subscription->active());
    }

    public function testHandleSubscriptionOnHold(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildGooglePayload(notificationType: 5);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('past_due', $result['new_status']);

        $subscription = $this->findSubscription();
        $this->assertEquals('past_due', $subscription->stripe_status);
    }

    public function testHandleSubscriptionRecovered(): void
    {
        $this->seedSubscription('past_due');

        $payload = $this->buildGooglePayload(notificationType: 1);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('active', $result['new_status']);

        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
    }

    public function testHandleSubscriptionPaused(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildGooglePayload(notificationType: 10);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('paused', $result['new_status']);

        $subscription = $this->findSubscription();
        $this->assertEquals('paused', $subscription->stripe_status);
    }

    public function testHandleMissingSubscription(): void
    {
        $this->purchaseToken = 'google_unknown_token_' . uniqid();

        $payload = $this->buildGooglePayload(notificationType: 3);

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('No matching Google Play subscription found', $result['message']);
    }

    private function fakePlaySubscription(array $response): void
    {
        $purchase = SubscriptionPurchase::fromArray($response);

        $this->bindPlayBilling(fn (): SubscriptionPurchase => $purchase);
    }

    private function failPlaySubscription(RuntimeException $exception): void
    {
        $this->bindPlayBilling(fn (): never => throw $exception);
    }

    private function withoutPlayAccount(array $settings = []): void
    {
        $this->bindPlayBilling(
            fn (): never => throw new RuntimeException('Play must not be called without a payment account'),
            $settings
        );
    }

    private function bindPlayBilling(Closure $respond, array $settings = self::PLAY_SETTINGS): void
    {
        $factory = fn ($container, array $parameters): GooglePlayBillingService => new class (
            $this->appWithSettings($settings),
            $respond
        ) extends GooglePlayBillingService {
            public function __construct(AppInterface $app, private readonly Closure $respond)
            {
                parent::__construct($app);
            }

            public function getSubscription(
                string $subscriptionId,
                string $purchaseToken
            ): SubscriptionPurchase {
                return ($this->respond)();
            }
        };

        $this->app->bind(GooglePlayBillingService::class, $factory);
    }

    private function billingFor(array $settings): GooglePlayBillingService
    {
        return new GooglePlayBillingService($this->appWithSettings($settings));
    }

    /**
     * Writing real app settings would leak them to parallel tests through Redis.
     */
    private function appWithSettings(array $settings): Apps
    {
        return new class ($settings) extends Apps {
            public function __construct(private readonly array $fakeSettings = [])
            {
                parent::__construct();
            }

            public function get(string $key, mixed $defaultValue = null): mixed
            {
                return $this->fakeSettings[$key] ?? $defaultValue;
            }
        };
    }

    private function seedSubscription(string $status, ?int $appsId = null): void
    {
        $user = auth()->user();

        $appsStripeCustomer = AppsStripeCustomer::updateOrCreate(
            [
                'apps_id' => $appsId ?? app(Apps::class)->getId(),
                'companies_id' => 0,
                'users_id' => $user->getId(),
                'provider' => 'google_play',
            ],
            [
                'stripe_id' => $this->purchaseToken,
                'config' => ['product_id' => $this->subscriptionId],
            ]
        );

        CashierSubscription::updateOrCreate(
            [
                'stripe_id' => $this->purchaseToken,
            ],
            [
                'apps_stripe_customer_id' => $appsStripeCustomer->getId(),
                'type' => 'google_play:' . $this->subscriptionId,
                'stripe_price' => $this->subscriptionId,
                'stripe_status' => $status,
                'quantity' => 1,
                'ends_at' => now()->addMonth(),
            ]
        );
    }

    private function findSubscription(?string $purchaseToken = null): CashierSubscription
    {
        return CashierSubscription::where('stripe_id', $purchaseToken ?? $this->purchaseToken)
            ->firstOrFail()
            ->fresh();
    }

    private function buildGooglePayload(int $notificationType = 3, bool $isTest = false): array
    {
        if ($isTest) {
            $data = [
                'version' => '1.0',
                'packageName' => 'com.test.app',
                'eventTimeMillis' => (string) now()->getTimestampMs(),
                'testNotification' => [
                    'version' => '1.0',
                ],
            ];
        } else {
            $data = [
                'version' => '1.0',
                'packageName' => 'com.test.app',
                'eventTimeMillis' => (string) now()->getTimestampMs(),
                'subscriptionNotification' => [
                    'version' => '1.0',
                    'notificationType' => $notificationType,
                    'purchaseToken' => $this->purchaseToken,
                    'subscriptionId' => $this->subscriptionId,
                ],
            ];
        }

        return [
            'message' => [
                'data' => base64_encode(json_encode($data)),
                'messageId' => uniqid('msg_'),
            ],
        ];
    }

    private function dispatchWebhookJob(array $payload): ?array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessGoogleSubscriptionWebhookJob::class],
            ['name' => 'ProcessGoogleSubscriptionWebhookJob']
        );

        $receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => ['provider' => 'google_play'],
            ]);

        $request = Request::create('https://localhost/v1/receiver/' . $receiver->uuid, 'POST', $payload);
        $webhookRequest = new ProcessWebhookAttemptAction($receiver, $request)->execute();

        Queue::fake();
        $job = new ProcessGoogleSubscriptionWebhookJob($webhookRequest);

        return $job->handle();
    }
}
