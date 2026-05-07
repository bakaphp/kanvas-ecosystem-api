<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Jobs\ProcessGoogleSubscriptionWebhookJob;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Laravel\Cashier\Subscription as CashierSubscription;
use Tests\TestCase;

class ProcessGoogleSubscriptionWebhookJobTest extends TestCase
{
    private string $purchaseToken;
    private string $subscriptionId = 'com.test.premium.monthly';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchaseToken = 'google_test_token_' . uniqid();
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

    private function seedSubscription(string $status): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $appsStripeCustomer = AppsStripeCustomer::updateOrCreate(
            [
                'apps_id' => $app->getId(),
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

    private function findSubscription(): CashierSubscription
    {
        $app = app(Apps::class);

        /** @var AppsStripeCustomer $customer */
        $customer = AppsStripeCustomer::where('apps_id', $app->getId())
            ->where('provider', 'google_play')
            ->where('stripe_id', $this->purchaseToken)
            ->firstOrFail();

        /** @var CashierSubscription $subscription */
        $subscription = CashierSubscription::where('apps_stripe_customer_id', $customer->getId())
            ->where('stripe_id', $this->purchaseToken)
            ->firstOrFail();

        return $subscription->fresh();
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

    private function dispatchWebhookJob(array $payload): array
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
