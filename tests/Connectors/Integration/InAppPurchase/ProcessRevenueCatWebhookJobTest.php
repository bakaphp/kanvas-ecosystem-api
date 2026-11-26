<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\InAppPurchase;

use Baka\Contracts\AppInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Jobs\ProcessRevenueCatWebhookJob;
use Kanvas\Connectors\InAppPurchase\Services\RevenueCatService;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Laravel\Cashier\Subscription as CashierSubscription;
use Tests\TestCase;

class ProcessRevenueCatWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow'];

    private const string SECRET = 'Bearer rc-test-secret';
    private const string API_KEY = 'sk_test_revenuecat';
    private const string PRODUCT_ID = 'fitmaxxing_pro_monthly';

    private string $originalTransactionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTransactionId = (string) random_int(100000000000, 999999999999);
        Http::preventStrayRequests();
        $this->bindRevenueCat(apiKey: '');
    }

    public function testRequestWithoutAuthorizationHeaderIsRejected(): void
    {
        $receiver = $this->createReceiver();

        $this->assertFalse(ProcessRevenueCatWebhookJob::authenticateRequest($this->request([], null), $receiver));
    }

    public function testRequestWithWrongAuthorizationHeaderIsRejected(): void
    {
        $receiver = $this->createReceiver();

        $this->assertFalse(
            ProcessRevenueCatWebhookJob::authenticateRequest($this->request([], 'Bearer wrong'), $receiver)
        );
    }

    public function testReceiverWithoutSecretRejectsEveryRequest(): void
    {
        $receiver = $this->createReceiver(['provider' => 'revenuecat']);

        $this->assertFalse(ProcessRevenueCatWebhookJob::authenticateRequest($this->request([], ''), $receiver));
        $this->assertFalse(ProcessRevenueCatWebhookJob::authenticateRequest($this->request([], 'Bearer '), $receiver));
    }

    public function testConfiguredAuthorizationHeaderIsAccepted(): void
    {
        $receiver = $this->createReceiver();

        $this->assertTrue(ProcessRevenueCatWebhookJob::authenticateRequest($this->request([]), $receiver));
    }

    public function testReceiverEndpointAnswers401WithoutTheSecret(): void
    {
        $receiver = $this->createReceiver();

        $this->postJson('/v1/receiver/' . $receiver->uuid, $this->event('INITIAL_PURCHASE'))
            ->assertStatus(401);
        $this->assertNull($this->findSubscription());
    }

    public function testTestEventIsAcknowledgedWithoutWriting(): void
    {
        $result = $this->dispatch($this->event('TEST'));

        $this->assertSame('Test event received', $result['message']);
        $this->assertNull($this->findSubscription());
    }

    public function testEventThatChangesNoSubscriptionIsSkipped(): void
    {
        $unknown = $this->dispatch($this->event('SOMETHING_NEW'));
        $nonRenewing = $this->dispatch($this->event('NON_RENEWING_PURCHASE', ['expiration_at_ms' => null]));

        $this->assertSame('skipped', $unknown['status']);
        $this->assertSame('skipped', $nonRenewing['status']);
        $this->assertNull($this->findSubscription());
    }

    public function testInitialPurchaseTrialCreatesActiveSubscription(): void
    {
        $expiresAt = now()->addWeek()->startOfSecond();

        $result = $this->dispatch($this->event('INITIAL_PURCHASE', [
            'period_type' => 'TRIAL',
            'expiration_at_ms' => $expiresAt->getTimestampMs(),
        ]));

        $this->assertSame('RevenueCat webhook processed', $result['message']);
        $this->assertSame('active', $result['new_status']);
        $this->assertSame('event', $result['source']);
        $this->assertSame(auth()->user()->uuid, $result['user_uuid']);

        $subscription = $this->findSubscription();
        $this->assertSame('revenuecat:' . self::PRODUCT_ID, $subscription->type);
        $this->assertSame(self::PRODUCT_ID, $subscription->stripe_price);
        $this->assertSame($expiresAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertSame($expiresAt->toDateTimeString(), $subscription->trial_ends_at->toDateTimeString());
        $this->assertTrue($subscription->active());

        $customer = $subscription->owner;
        $this->assertSame('revenuecat', $customer->provider);
        $this->assertSame(0, (int) $customer->companies_id);
        $this->assertSame('rc:' . $this->originalTransactionId, $customer->stripe_id);
        $this->assertSame('SANDBOX', $customer->config['environment']);
        $this->assertSame('APP_STORE', $customer->config['store']);

        $this->graphQL('
            query {
                me {
                    currentSubscription {
                        stripe_id
                        stripe_status
                        provider
                        is_active
                    }
                }
            }
        ')->assertSuccessful()->assertJson([
            'data' => [
                'me' => [
                    'currentSubscription' => [
                        'stripe_id' => 'rc:' . $this->originalTransactionId,
                        'stripe_status' => 'active',
                        'provider' => 'revenuecat',
                        'is_active' => true,
                    ],
                ],
            ],
        ]);
    }

    public function testRenewalExtendsEndsAt(): void
    {
        $this->dispatch($this->event('INITIAL_PURCHASE', [
            'period_type' => 'TRIAL',
            'event_timestamp_ms' => now()->subDays(7)->getTimestampMs(),
        ]));
        $nextExpiry = now()->addMonth()->startOfSecond();

        $result = $this->dispatch($this->event('RENEWAL', ['expiration_at_ms' => $nextExpiry->getTimestampMs()]));

        $this->assertSame('active', $result['new_status']);
        $subscription = $this->findSubscription();
        $this->assertSame($nextExpiry->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertNull($subscription->trial_ends_at);
    }

    public function testCancellationKeepsAccessUntilExpiry(): void
    {
        $this->dispatch($this->event('INITIAL_PURCHASE', ['event_timestamp_ms' => now()->subDay()->getTimestampMs()]));
        $paidUntil = now()->addDays(10)->startOfSecond();

        $result = $this->dispatch($this->event('CANCELLATION', [
            'cancel_reason' => 'UNSUBSCRIBE',
            'expiration_at_ms' => $paidUntil->getTimestampMs(),
        ]));

        $this->assertSame('canceled', $result['new_status']);
        $subscription = $this->findSubscription();
        $this->assertSame($paidUntil->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertTrue($subscription->active());
    }

    public function testExpirationEndsAccess(): void
    {
        $this->dispatch($this->event('INITIAL_PURCHASE', ['event_timestamp_ms' => now()->subMonth()->getTimestampMs()]));
        $expiredAt = now()->subMinute()->startOfSecond();

        $result = $this->dispatch($this->event('EXPIRATION', [
            'expiration_reason' => 'UNSUBSCRIBE',
            'expiration_at_ms' => $expiredAt->getTimestampMs(),
        ]));

        $this->assertSame('expired', $result['new_status']);
        $subscription = $this->findSubscription();
        $this->assertSame($expiredAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertFalse($subscription->active());
    }

    public function testRefundEndsAccessAtTheRefund(): void
    {
        $this->dispatch($this->event('INITIAL_PURCHASE', ['event_timestamp_ms' => now()->subDays(3)->getTimestampMs()]));
        $refundedAt = now()->subMinutes(5)->startOfSecond();

        $result = $this->dispatch($this->event('CANCELLATION', [
            'cancel_reason' => 'CUSTOMER_SUPPORT',
            'expiration_at_ms' => $refundedAt->getTimestampMs(),
            'event_timestamp_ms' => now()->getTimestampMs(),
        ]));

        $this->assertSame('expired', $result['new_status']);
        $subscription = $this->findSubscription();
        $this->assertSame($refundedAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertFalse($subscription->active());
    }

    public function testBillingIssueKeepsAccessThroughTheGracePeriod(): void
    {
        $graceEnds = now()->addDays(6)->startOfSecond();

        $result = $this->dispatch($this->event('BILLING_ISSUE', [
            'expiration_at_ms' => now()->subHour()->getTimestampMs(),
            'grace_period_expiration_at_ms' => $graceEnds->getTimestampMs(),
        ]));

        $this->assertSame('active', $result['new_status']);
        $subscription = $this->findSubscription();
        $this->assertSame($graceEnds->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertTrue($subscription->active());
    }

    public function testBillingIssueAfterTheGracePeriodIsPastDue(): void
    {
        $result = $this->dispatch($this->event('BILLING_ISSUE', [
            'expiration_at_ms' => now()->subDays(8)->getTimestampMs(),
            'grace_period_expiration_at_ms' => now()->subDay()->getTimestampMs(),
        ]));

        $this->assertSame('past_due', $result['new_status']);
        $this->assertFalse($this->findSubscription()->active());
    }

    public function testUnknownCustomerIsSkipped(): void
    {
        $unknown = (string) Str::uuid();

        $result = $this->dispatch($this->event('INITIAL_PURCHASE', [
            'app_user_id' => $unknown,
            'original_app_user_id' => $unknown,
            'aliases' => [$unknown],
        ]));

        $this->assertSame('skipped', $result['status']);
        $this->assertNull($this->findSubscription());
    }

    public function testAnonymousCustomerIsSkipped(): void
    {
        $anonymous = '$RCAnonymousID:' . Str::random(32);

        $result = $this->dispatch($this->event('INITIAL_PURCHASE', [
            'app_user_id' => $anonymous,
            'original_app_user_id' => $anonymous,
            'aliases' => [$anonymous],
        ]));

        $this->assertSame('skipped', $result['status']);
        $this->assertNull($this->findSubscription());
    }

    public function testAliasResolvesTheUser(): void
    {
        $anonymous = '$RCAnonymousID:' . Str::random(32);

        $result = $this->dispatch($this->event('INITIAL_PURCHASE', [
            'app_user_id' => $anonymous,
            'original_app_user_id' => $anonymous,
            'aliases' => [$anonymous, auth()->user()->uuid],
        ]));

        $this->assertSame('active', $result['new_status']);
        $this->assertSame(auth()->user()->getId(), (int) $this->findSubscription()->owner->users_id);
    }

    public function testDuplicateDeliveryIsIdempotent(): void
    {
        $payload = $this->event('INITIAL_PURCHASE');

        $first = $this->dispatch($payload);
        $firstRow = $this->findSubscription()->toArray();
        $second = $this->dispatch($payload);

        $this->assertSame('RevenueCat webhook processed', $first['message']);
        $this->assertSame('Duplicate or older RevenueCat event, current access left unchanged', $second['message']);
        $this->assertSame($firstRow, $this->findSubscription()->toArray());
        $this->assertSame(1, CashierSubscription::where('stripe_id', 'rc:' . $this->originalTransactionId)->count());
    }

    public function testOlderEventDeliveredLateIsIgnored(): void
    {
        $paidUntil = now()->addMonth()->startOfSecond();
        $this->dispatch($this->event('RENEWAL', ['expiration_at_ms' => $paidUntil->getTimestampMs()]));

        $result = $this->dispatch($this->event('EXPIRATION', [
            'expiration_at_ms' => now()->subDay()->getTimestampMs(),
            'event_timestamp_ms' => now()->subDay()->getTimestampMs(),
        ]));

        $this->assertSame('Duplicate or older RevenueCat event, current access left unchanged', $result['message']);
        $subscription = $this->findSubscription();
        $this->assertSame('active', $subscription->stripe_status);
        $this->assertSame($paidUntil->toDateTimeString(), $subscription->ends_at->toDateTimeString());
    }

    public function testApiStateWinsOverTheEventType(): void
    {
        $this->bindRevenueCat(self::API_KEY);
        $paidUntil = now()->addMonth()->startOfSecond();
        $this->fakeSubscriber([
            'expires_date' => $paidUntil->toIso8601ZuluString(),
            'period_type' => 'normal',
            'store' => 'app_store',
            'is_sandbox' => false,
        ]);

        $result = $this->dispatch($this->event('EXPIRATION', [
            'expiration_at_ms' => now()->subDay()->getTimestampMs(),
        ]));

        $this->assertSame('revenuecat_api', $result['source']);
        $this->assertSame('active', $result['new_status']);
        $subscription = $this->findSubscription();
        $this->assertSame($paidUntil->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertTrue($subscription->active());
        $this->assertSame('PRODUCTION', $subscription->owner->config['environment']);
        Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer ' . self::API_KEY)
            && $request->url() === 'https://api.revenuecat.com/v1/subscribers/' . auth()->user()->uuid);
    }

    public function testApiReportsCancellationAndRefund(): void
    {
        $this->bindRevenueCat(self::API_KEY);
        $refundedAt = now()->subHour()->startOfSecond();
        $this->fakeSubscriber([
            'expires_date' => now()->addMonth()->toIso8601ZuluString(),
            'unsubscribe_detected_at' => $refundedAt->toIso8601ZuluString(),
            'refunded_at' => $refundedAt->toIso8601ZuluString(),
        ]);

        $result = $this->dispatch($this->event('RENEWAL'));

        $this->assertSame('expired', $result['new_status']);
        $subscription = $this->findSubscription();
        $this->assertSame($refundedAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
        $this->assertFalse($subscription->active());
    }

    public function testApiWithoutTheProductWritesNothing(): void
    {
        $this->bindRevenueCat(self::API_KEY);
        Http::fake(['api.revenuecat.com/*' => Http::response(['subscriber' => ['subscriptions' => []]])]);

        $result = $this->dispatch($this->event('INITIAL_PURCHASE'));

        $this->assertSame('skipped', $result['status']);
        $this->assertNull($this->findSubscription());
    }

    public function testApiFailureLeavesTheRowUnchanged(): void
    {
        Exceptions::fake();
        $this->dispatch($this->event('INITIAL_PURCHASE', ['event_timestamp_ms' => now()->subDay()->getTimestampMs()]));
        $before = $this->findSubscription()->toArray();
        $this->bindRevenueCat(self::API_KEY);
        Http::fake(['api.revenuecat.com/*' => Http::response(['message' => 'unavailable'], 503)]);

        $result = $this->dispatch($this->event('EXPIRATION', ['expiration_at_ms' => now()->subDay()->getTimestampMs()]));

        $this->assertNull($result);
        $this->assertSame($before, $this->findSubscription()->toArray());
    }

    public function testRowOwnedByAnotherUserIsRefused(): void
    {
        $otherUser = $this->createUser();
        $this->seedSubscription($otherUser);

        $result = $this->dispatch($this->event('EXPIRATION', ['expiration_at_ms' => now()->subDay()->getTimestampMs()]));

        $this->assertSame('rejected', $result['status']);
        $subscription = $this->findSubscription();
        $this->assertSame($otherUser->getId(), (int) $subscription->owner->users_id);
        $this->assertTrue($subscription->active());
    }

    public function testTransferMovesTheRowToTheNewOwner(): void
    {
        $previousOwner = $this->createUser();
        $this->seedSubscription($previousOwner);
        $endsAt = $this->findSubscription()->ends_at;

        $result = $this->dispatch($this->transferEvent([$previousOwner->uuid], [auth()->user()->uuid]));

        $this->assertSame(['rc:' . $this->originalTransactionId], $result['moved']);
        $subscription = $this->findSubscription();
        $this->assertSame(auth()->user()->getId(), (int) $subscription->owner->users_id);
        $this->assertSame('active', $subscription->stripe_status);
        $this->assertSame($endsAt->toDateTimeString(), $subscription->ends_at->toDateTimeString());
    }

    public function testTransferToACustomerOutsideTheAppEndsAccess(): void
    {
        $previousOwner = $this->createUser();
        $this->seedSubscription($previousOwner);

        $result = $this->dispatch($this->transferEvent([$previousOwner->uuid], ['$RCAnonymousID:' . Str::random(32)]));

        $this->assertSame(['rc:' . $this->originalTransactionId], $result['ended']);
        $subscription = $this->findSubscription();
        $this->assertSame($previousOwner->getId(), (int) $subscription->owner->users_id);
        $this->assertSame('expired', $subscription->stripe_status);
        $this->assertFalse($subscription->active());
    }

    public function testSetupCommandCreatesTheReceiverWithASecret(): void
    {
        $user = auth()->user();

        $this->artisan('kanvas:iap-webhook:setup', [
            'app_id' => app(Apps::class)->getId(),
            'company_id' => $user->getCurrentCompany()->getId(),
            'user_id' => $user->getId(),
            '--provider' => ['revenuecat'],
        ])->assertSuccessful();

        $receiver = ReceiverWebhook::query()
            ->forJob(ProcessRevenueCatWebhookJob::class)
            ->where('apps_id', app(Apps::class)->getId())
            ->where('companies_id', $user->getCurrentCompany()->getId())
            ->firstOrFail();
        $secret = $receiver->configuration[ProcessRevenueCatWebhookJob::AUTHORIZATION_CONFIG_KEY];
        $this->assertStringStartsWith('Bearer ', $secret);
        $this->assertTrue(ProcessRevenueCatWebhookJob::authenticateRequest($this->request([], $secret), $receiver));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function event(string $type, array $overrides = []): array
    {
        $uuid = auth()->user()->uuid;

        return [
            'api_version' => '1.0',
            'event' => array_merge([
                'id' => (string) Str::uuid(),
                'type' => $type,
                'app_user_id' => $uuid,
                'original_app_user_id' => $uuid,
                'aliases' => [$uuid],
                'product_id' => self::PRODUCT_ID,
                'entitlement_ids' => ['pro'],
                'period_type' => 'NORMAL',
                'purchased_at_ms' => now()->subDay()->getTimestampMs(),
                'expiration_at_ms' => now()->addMonth()->getTimestampMs(),
                'event_timestamp_ms' => now()->getTimestampMs(),
                'environment' => 'SANDBOX',
                'store' => 'APP_STORE',
                'original_transaction_id' => $this->originalTransactionId,
                'transaction_id' => (string) random_int(100000000000, 999999999999),
            ], $overrides),
        ];
    }

    /**
     * RevenueCat's transfer event names no product or transaction.
     *
     * @param string[] $from
     * @param string[] $to
     */
    private function transferEvent(array $from, array $to): array
    {
        return [
            'api_version' => '1.0',
            'event' => [
                'id' => (string) Str::uuid(),
                'type' => 'TRANSFER',
                'event_timestamp_ms' => now()->getTimestampMs(),
                'environment' => 'SANDBOX',
                'store' => 'APP_STORE',
                'transferred_from' => $from,
                'transferred_to' => $to,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function fakeSubscriber(array $subscription): void
    {
        Http::fake([
            'api.revenuecat.com/v1/subscribers/*' => Http::response([
                'request_date_ms' => now()->getTimestampMs(),
                'subscriber' => [
                    'original_app_user_id' => auth()->user()->uuid,
                    'subscriptions' => [
                        self::PRODUCT_ID => $subscription + [
                            'billing_issues_detected_at' => null,
                            'grace_period_expires_date' => null,
                            'is_sandbox' => true,
                            'period_type' => 'normal',
                            'purchase_date' => now()->subDay()->toIso8601ZuluString(),
                            'refunded_at' => null,
                            'store' => 'app_store',
                            'unsubscribe_detected_at' => null,
                        ],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * Writing a real app setting would leak the key to parallel tests through Redis.
     */
    private function bindRevenueCat(string $apiKey): void
    {
        $this->app->bind(
            RevenueCatService::class,
            fn ($container, array $parameters): RevenueCatService => new class ($parameters['app'], $apiKey) extends RevenueCatService {
                public function __construct(AppInterface $app, private readonly string $apiKey)
                {
                    parent::__construct($app);
                }

                protected function secretKey(): string
                {
                    return $this->apiKey;
                }
            }
        );
    }

    private function seedSubscription(Users $owner): void
    {
        $customer = AppsStripeCustomer::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => 0,
            'users_id' => $owner->getId(),
            'provider' => 'revenuecat',
            'stripe_id' => 'rc:' . $this->originalTransactionId,
            'config' => ['product_id' => self::PRODUCT_ID],
        ]);

        CashierSubscription::create([
            'apps_stripe_customer_id' => $customer->getId(),
            'type' => 'revenuecat:' . self::PRODUCT_ID,
            'stripe_id' => 'rc:' . $this->originalTransactionId,
            'stripe_price' => self::PRODUCT_ID,
            'stripe_status' => 'active',
            'quantity' => 1,
            'ends_at' => now()->addMonth()->startOfSecond(),
        ]);
    }

    private function findSubscription(): ?CashierSubscription
    {
        return CashierSubscription::with('owner')
            ->where('stripe_id', 'rc:' . $this->originalTransactionId)
            ->first();
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function createReceiver(array $configuration = ['authorization' => self::SECRET]): ReceiverWebhook
    {
        $user = auth()->user();
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessRevenueCatWebhookJob::class],
            ['name' => 'ProcessRevenueCatWebhookJob']
        );

        return ReceiverWebhook::factory()
            ->app(app(Apps::class)->getId())
            ->user($user->getId())
            ->company($user->getCurrentCompany()->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => $configuration,
            ]);
    }

    private function request(array $payload, ?string $authorization = self::SECRET): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($authorization !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        return Request::create(
            'https://localhost/v1/receiver/revenuecat',
            'POST',
            server: $server,
            content: (string) json_encode($payload)
        );
    }

    private function dispatch(array $payload): ?array
    {
        $receiver = $this->createReceiver();
        $webhookRequest = new ProcessWebhookAttemptAction($receiver, $this->request($payload))->execute();

        Queue::fake();

        return new ProcessRevenueCatWebhookJob($webhookRequest)->handle();
    }
}
