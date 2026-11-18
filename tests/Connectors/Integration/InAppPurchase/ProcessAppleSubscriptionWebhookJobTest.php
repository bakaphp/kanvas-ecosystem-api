<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Imdhemy\AppStore\Jws\JsonWebSignature;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Jobs\ProcessAppleSubscriptionWebhookJob;
use Kanvas\Connectors\InAppPurchase\Services\AppleJwsVerifier;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Laravel\Cashier\Subscription as CashierSubscription;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the job reads APPLE_BUNDLE_ID, an app setting shared by every parallel process.
 */
#[Group('serial')]
class ProcessAppleSubscriptionWebhookJobTest extends TestCase
{
    private const string BUNDLE_ID = 'com.test.app';

    private string $originalTransactionId;
    private string $productId = 'com.test.premium.monthly';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTransactionId = 'apple_test_txn_' . uniqid();
        $this->verifierAnswers(true);
        app(Apps::class)->set(ConfigurationEnum::APPLE_BUNDLE_ID->value, self::BUNDLE_ID);
    }

    protected function tearDown(): void
    {
        app(Apps::class)->del(ConfigurationEnum::APPLE_BUNDLE_ID->value);

        parent::tearDown();
    }

    public function testRejectsNotificationOfAnotherBundle(): void
    {
        $this->seedSubscription('active');
        $endsAt = $this->findSubscription()->ends_at;

        $result = $this->dispatchWebhookJob($this->buildApplePayload('REFUND', bundleId: 'com.someone.else'));

        $this->assertEquals('rejected', $result['status']);
        $this->assertStringContainsString('belongs to another app', $result['message']);
        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertEquals($endsAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
    }

    public function testRejectsEveryNotificationWhenBundleIdIsNotConfigured(): void
    {
        app(Apps::class)->del(ConfigurationEnum::APPLE_BUNDLE_ID->value);
        $this->seedSubscription('active');

        $result = $this->dispatchWebhookJob($this->buildApplePayload('REFUND'));

        $this->assertEquals('rejected', $result['status']);
        $this->assertStringContainsString(ConfigurationEnum::APPLE_BUNDLE_ID->value, $result['message']);
        $this->assertEquals('active', $this->findSubscription()->stripe_status);
    }

    public function testRejectsPayloadNotSignedByApple(): void
    {
        $this->app->forgetInstance(AppleJwsVerifier::class);
        $this->seedSubscription('active');

        $result = $this->dispatchWebhookJob($this->buildApplePayload('REFUND'));

        $this->assertEquals('rejected', $result['status']);
        $this->assertEquals('active', $this->findSubscription()->stripe_status);
    }

    public function testVerifierRejectionLeavesSubscriptionUnchanged(): void
    {
        $this->verifierAnswers(false);
        $this->seedSubscription('active');
        $endsAt = $this->findSubscription()->ends_at;

        $result = $this->dispatchWebhookJob($this->buildApplePayload('REFUND'));

        $this->assertEquals('rejected', $result['status']);
        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertEquals($endsAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
    }

    public function testHandleTestNotification(): void
    {
        $payload = $this->buildApplePayload('TEST');

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('Test notification received', $result['message']);
        $this->assertEquals('TEST', $result['notification_type']);
    }

    public function testHandleMissingSignedPayload(): void
    {
        $result = $this->dispatchWebhookJob([]);

        $this->assertIsArray($result);
        $this->assertEquals('Missing signedPayload', $result['message']);
        $this->assertEquals('skipped', $result['status']);
    }

    public function testHandleSubscriptionCancellation(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildApplePayload(
            'DID_CHANGE_RENEWAL_STATUS',
            'AUTO_RENEW_DISABLED'
        );

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('Apple subscription webhook processed', $result['message']);
        $this->assertEquals('canceled', $result['new_status']);
        $this->assertEquals($this->originalTransactionId, $result['original_transaction_id']);

        $subscription = $this->findSubscription();
        $this->assertEquals('canceled', $subscription->stripe_status);
    }

    public function testHandleSubscriptionRenewal(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildApplePayload('DID_RENEW');

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('active', $result['new_status']);

        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
    }

    public function testHandleSubscriptionExpiration(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildApplePayload('EXPIRED');

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('expired', $result['new_status']);

        $subscription = $this->findSubscription();
        $this->assertEquals('expired', $subscription->stripe_status);
    }

    public function testHandleSubscriptionRefund(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildApplePayload('REFUND');

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('canceled', $result['new_status']);

        $subscription = $this->findSubscription();
        $this->assertEquals('canceled', $subscription->stripe_status);
        $this->assertTrue($subscription->ends_at->lessThanOrEqualTo(now()));
        $this->assertFalse($subscription->active());
    }

    public function testRefundOfEarlierPeriodLeavesCurrentAccess(): void
    {
        $this->seedSubscription('active');
        $currentEndsAt = $this->findSubscription()->ends_at;

        $result = $this->dispatchWebhookJob(
            $this->buildApplePayload('REFUND', expiresDate: now()->subMonth())
        );

        $this->assertStringContainsString('earlier period', $result['message']);
        $this->assertArrayNotHasKey('new_status', $result);
        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertEquals($currentEndsAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertTrue($subscription->active());
    }

    public function testLateRenewalOfEarlierPeriodDoesNotRollBackEndsAt(): void
    {
        $this->seedSubscription('active');
        $currentEndsAt = now()->addMonths(2)->startOfSecond();
        $this->findSubscription()->forceFill(['ends_at' => $currentEndsAt])->saveOrFail();

        $result = $this->dispatchWebhookJob(
            $this->buildApplePayload('DID_RENEW', expiresDate: now()->addMonth())
        );

        $this->assertStringContainsString('earlier period', $result['message']);
        $this->assertArrayNotHasKey('new_status', $result);
        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertEquals($currentEndsAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
    }

    public function testLateExpirationOfEarlierPeriodLeavesStatusUnchanged(): void
    {
        $this->seedSubscription('active');

        $this->dispatchWebhookJob($this->buildApplePayload('EXPIRED', expiresDate: now()->subDay()));

        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertTrue($subscription->active());
    }

    public function testUpdatesOlderLineageAfterCustomerMovedToNewerOne(): void
    {
        $olderTransactionId = $this->originalTransactionId;
        $this->seedSubscription('active');
        $this->originalTransactionId = 'apple_test_txn_' . uniqid();
        $this->seedSubscription('active');
        $this->originalTransactionId = $olderTransactionId;

        $result = $this->dispatchWebhookJob(
            $this->buildApplePayload('DID_CHANGE_RENEWAL_STATUS', 'AUTO_RENEW_DISABLED')
        );

        $this->assertEquals('canceled', $result['new_status']);
        $this->assertEquals('canceled', $this->findSubscription($olderTransactionId)->stripe_status);
    }

    public function testDoesNotTouchSubscriptionOfAnotherApp(): void
    {
        $this->seedSubscription('active', appsId: app(Apps::class)->getId() + 1000000);
        $endsAt = $this->findSubscription()->ends_at;

        $result = $this->dispatchWebhookJob($this->buildApplePayload('REFUND'));

        $this->assertEquals('No matching Apple subscription found', $result['message']);
        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
        $this->assertEquals($endsAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
    }

    public function testHandleSubscriptionRenewalExtendsEndsAt(): void
    {
        $this->seedSubscription('active');
        $this->findSubscription()->forceFill(['ends_at' => now()->subDay()])->saveOrFail();

        $this->dispatchWebhookJob($this->buildApplePayload('DID_RENEW'));

        $subscription = $this->findSubscription();
        $this->assertTrue($subscription->ends_at->greaterThan(now()->addWeeks(4)));
        $this->assertTrue($subscription->active());
    }

    public function testHandleBillingFailure(): void
    {
        $this->seedSubscription('active');

        $payload = $this->buildApplePayload('DID_FAIL_TO_RENEW');

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('past_due', $result['new_status']);

        $subscription = $this->findSubscription();
        $this->assertEquals('past_due', $subscription->stripe_status);
    }

    public function testHandleAutoRenewReEnabled(): void
    {
        $this->seedSubscription('canceled');

        $payload = $this->buildApplePayload(
            'DID_CHANGE_RENEWAL_STATUS',
            'AUTO_RENEW_ENABLED'
        );

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('active', $result['new_status']);

        $subscription = $this->findSubscription();
        $this->assertEquals('active', $subscription->stripe_status);
    }

    public function testLatePreferenceChangeDoesNotReopenARefundedPeriod(): void
    {
        $this->seedSubscription('active');
        $this->dispatchWebhookJob($this->buildApplePayload('REFUND'));

        $result = $this->dispatchWebhookJob($this->buildApplePayload(
            'DID_CHANGE_RENEWAL_STATUS',
            'AUTO_RENEW_ENABLED'
        ));

        $this->assertArrayNotHasKey('new_status', $result);
        $subscription = $this->findSubscription();
        $this->assertEquals('canceled', $subscription->stripe_status);
        $this->assertFalse($subscription->active());
    }

    public function testLateRenewalOfARefundedPeriodDoesNotReopenAccess(): void
    {
        $this->seedSubscription('active');
        $this->dispatchWebhookJob($this->buildApplePayload('REFUND', revocationDate: now()->subMinute()));

        foreach (['DID_RENEW', 'SUBSCRIBED', 'OFFER_REDEEMED'] as $type) {
            $result = $this->dispatchWebhookJob($this->buildApplePayload(
                $type,
                expiresDate: now()->addMonth(),
                purchaseDate: now()->subDays(2)
            ));

            $this->assertStringContainsString('refunded period', $result['message'], $type);
        }

        $subscription = $this->findSubscription();
        $this->assertEquals('canceled', $subscription->stripe_status);
        $this->assertFalse($subscription->active());
    }

    public function testPurchaseAfterTheRefundReactivates(): void
    {
        $this->seedSubscription('active');
        $this->dispatchWebhookJob($this->buildApplePayload('REFUND', revocationDate: now()->subHour()));

        $result = $this->dispatchWebhookJob($this->buildApplePayload(
            'SUBSCRIBED',
            'RESUBSCRIBE',
            now()->addMonth(),
            purchaseDate: now()->subMinute()
        ));

        $this->assertEquals('active', $result['new_status']);
        $this->assertTrue($this->findSubscription()->active());
    }

    public function testPreferenceChangesOtherThanAutoRenewLeaveStatusAlone(): void
    {
        $this->seedSubscription('canceled');
        $paidUntil = $this->findSubscription()->ends_at->toDateTimeString();

        foreach (['PRICE_INCREASE', 'CONSUMPTION_REQUEST', 'REFUND_DECLINED', 'DID_CHANGE_RENEWAL_PREF'] as $type) {
            $result = $this->dispatchWebhookJob($this->buildApplePayload($type));

            $this->assertArrayNotHasKey('new_status', $result, $type);
        }

        $subscription = $this->findSubscription();
        $this->assertEquals('canceled', $subscription->stripe_status);
        $this->assertEquals($paidUntil, $subscription->ends_at->toDateTimeString());
    }

    public function testPreferenceChangeKeepsThePaidPeriod(): void
    {
        $this->seedSubscription('active');
        $paidUntil = $this->findSubscription()->ends_at->toDateTimeString();

        $this->dispatchWebhookJob($this->buildApplePayload(
            'DID_CHANGE_RENEWAL_STATUS',
            'AUTO_RENEW_DISABLED',
            now()->addYear()
        ));

        $subscription = $this->findSubscription();
        $this->assertEquals('canceled', $subscription->stripe_status);
        $this->assertEquals($paidUntil, $subscription->ends_at->toDateTimeString());
        $this->assertTrue($subscription->active());
    }

    public function testHandleMissingSubscription(): void
    {
        $unknownTxnId = 'apple_unknown_txn_' . uniqid();
        $this->originalTransactionId = $unknownTxnId;

        $payload = $this->buildApplePayload('DID_RENEW');

        $result = $this->dispatchWebhookJob($payload);

        $this->assertIsArray($result);
        $this->assertEquals('No matching Apple subscription found', $result['message']);
        $this->assertEquals($unknownTxnId, $result['original_transaction_id']);
    }

    private function verifierAnswers(bool $isSignedByApple): void
    {
        $this->app->instance(AppleJwsVerifier::class, new class ($isSignedByApple) extends AppleJwsVerifier {
            public function __construct(private readonly bool $isSignedByApple)
            {
            }

            public function verify(JsonWebSignature $jws): bool
            {
                return $this->isSignedByApple;
            }
        });
    }

    private function seedSubscription(string $status, ?int $appsId = null): void
    {
        $user = auth()->user();

        $appsStripeCustomer = AppsStripeCustomer::updateOrCreate(
            [
                'apps_id' => $appsId ?? app(Apps::class)->getId(),
                'companies_id' => 0,
                'users_id' => $user->getId(),
                'provider' => 'apple',
            ],
            [
                'stripe_id' => $this->originalTransactionId,
                'config' => ['product_id' => $this->productId],
            ]
        );

        CashierSubscription::updateOrCreate(
            [
                'stripe_id' => $this->originalTransactionId,
            ],
            [
                'apps_stripe_customer_id' => $appsStripeCustomer->getId(),
                'type' => 'apple:' . $this->productId,
                'stripe_price' => $this->productId,
                'stripe_status' => $status,
                'quantity' => 1,
                'ends_at' => now()->addMonth(),
            ]
        );
    }

    private function findSubscription(?string $originalTransactionId = null): CashierSubscription
    {
        return CashierSubscription::where('stripe_id', $originalTransactionId ?? $this->originalTransactionId)
            ->firstOrFail()
            ->fresh();
    }

    private function buildApplePayload(
        string $notificationType,
        ?string $subtype = null,
        ?Carbon $expiresDate = null,
        ?Carbon $purchaseDate = null,
        ?Carbon $revocationDate = null,
        string $bundleId = self::BUNDLE_ID
    ): array {
        $transactionClaims = [
            'originalTransactionId' => $this->originalTransactionId,
            'transactionId' => 'txn_' . uniqid(),
            'productId' => $this->productId,
            'bundleId' => $bundleId,
            'purchaseDate' => ($purchaseDate ?? now()->subMinute())->getTimestampMs(),
            'expiresDate' => ($expiresDate ?? now()->addMonth())->getTimestampMs(),
            'environment' => 'Sandbox',
            'type' => 'Auto-Renewable Subscription',
        ];

        if ($revocationDate !== null) {
            $transactionClaims['revocationDate'] = $revocationDate->getTimestampMs();
        }

        $renewalClaims = [
            'originalTransactionId' => $this->originalTransactionId,
            'productId' => $this->productId,
            'autoRenewStatus' => $subtype === 'AUTO_RENEW_DISABLED' ? 0 : 1,
        ];

        $notificationClaims = [
            'notificationType' => $notificationType,
            'notificationUUID' => uniqid('notif_'),
            'version' => '2.0',
            'signedDate' => now()->getTimestampMs(),
            'data' => [
                'bundleId' => $bundleId,
                'environment' => 'Sandbox',
                'signedTransactionInfo' => $this->buildUnsignedJws($transactionClaims),
                'signedRenewalInfo' => $this->buildUnsignedJws($renewalClaims),
            ],
        ];

        if ($subtype !== null) {
            $notificationClaims['subtype'] = $subtype;
        }

        return [
            'signedPayload' => $this->buildUnsignedJws($notificationClaims),
        ];
    }

    private function buildUnsignedJws(array $claims): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'ES256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode($claims));
        $signature = $this->base64UrlEncode('test_signature');

        return "{$header}.{$payload}.{$signature}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function dispatchWebhookJob(array $payload): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessAppleSubscriptionWebhookJob::class],
            ['name' => 'ProcessAppleSubscriptionWebhookJob']
        );

        $receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => ['provider' => 'apple'],
            ]);

        $request = Request::create('https://localhost/v1/receiver/' . $receiver->uuid, 'POST', $payload);
        $webhookRequest = new ProcessWebhookAttemptAction($receiver, $request)->execute();

        Queue::fake();
        $job = new ProcessAppleSubscriptionWebhookJob($webhookRequest);

        return $job->handle();
    }
}
