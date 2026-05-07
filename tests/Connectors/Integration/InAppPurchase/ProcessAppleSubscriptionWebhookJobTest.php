<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Jobs\ProcessAppleSubscriptionWebhookJob;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Laravel\Cashier\Subscription as CashierSubscription;
use Tests\TestCase;

class ProcessAppleSubscriptionWebhookJobTest extends TestCase
{
    private string $originalTransactionId;
    private string $productId = 'com.test.premium.monthly';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTransactionId = 'apple_test_txn_' . uniqid();
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

    private function seedSubscription(string $status): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $appsStripeCustomer = AppsStripeCustomer::updateOrCreate(
            [
                'apps_id' => $app->getId(),
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

    private function findSubscription(): CashierSubscription
    {
        $app = app(Apps::class);

        /** @var AppsStripeCustomer $customer */
        $customer = AppsStripeCustomer::where('apps_id', $app->getId())
            ->where('provider', 'apple')
            ->where('stripe_id', $this->originalTransactionId)
            ->firstOrFail();

        /** @var CashierSubscription $subscription */
        $subscription = CashierSubscription::where('apps_stripe_customer_id', $customer->getId())
            ->where('stripe_id', $this->originalTransactionId)
            ->firstOrFail();

        return $subscription->fresh();
    }

    private function buildApplePayload(string $notificationType, ?string $subtype = null): array
    {
        $expiresDateMs = now()->addMonth()->getTimestampMs();

        $transactionClaims = [
            'originalTransactionId' => $this->originalTransactionId,
            'transactionId' => 'txn_' . uniqid(),
            'productId' => $this->productId,
            'bundleId' => 'com.test.app',
            'expiresDate' => $expiresDateMs,
            'environment' => 'Sandbox',
            'type' => 'Auto-Renewable Subscription',
        ];

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
                'bundleId' => 'com.test.app',
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
