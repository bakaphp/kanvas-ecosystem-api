<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum as StripeConfigurationEnum;
use Kanvas\Connectors\Stripe\Webhooks\StripeOrderPaymentWebhookJob;
use Kanvas\Souk\Taxes\Actions\ReleaseCheckoutHoldsAction;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Models\CreditReservation;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('serial')]
final class GatewayAttemptRecoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'ecosystem', 'workflow'];

    private const string WEBHOOK_SECRET = 'whsec_recovery_test_key';

    private Apps $kanvasApp;
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $company->set(StripeConfigurationEnum::STRIPE_WEBHOOK_SECRET->value, self::WEBHOOK_SECRET);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => StripeOrderPaymentWebhookJob::class],
            ['name' => 'StripeOrderPaymentWebhookJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    private function makeSnapshot(array $overrides = []): OrderTaxSnapshot
    {
        $user = auth()->user();

        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = null;
        $snapshot->cart_session_id = 'recovery-test-' . uniqid();
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = 'calculated';
        $snapshot->provider = 'manual_rate';
        $snapshot->input_hash = hash('sha256', json_encode([['variant_id' => 1, 'quantity' => 1, 'unit_price_net_amount' => 100.0]]));
        $snapshot->tax_amount = 18.00;
        $snapshot->taxable_amount = 100.00;
        $snapshot->remaining_reversible_amount = 100.00;
        $snapshot->remaining_reversible_tax = 18.00;
        $snapshot->gateway_attempt_key = hash('sha256', uniqid());
        $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->checkout_payload = [
            'lines' => [
                [
                    'uuid' => 'line-uuid-1',
                    'variant_id' => 1,
                    'product_name' => 'Test Product',
                    'product_sku' => 'SKU-001',
                    'quantity' => 1,
                    'unit_price_net_amount' => 100.00,
                    'tax_amount' => 18.00,
                ],
            ],
            'shipping_address' => null,
            'billing_address' => null,
            'discount_allocation' => [],
            'wallet_hold_transfer_id' => null,
            'credit_reservation_id' => null,
            'order_context' => [
                'region_id' => 1,
                'people_id' => 0,
                'companies_id' => $user->getCurrentCompany()->getId(),
                'users_id' => $user->getId(),
            ],
            'totals' => [
                'subtotal' => 100.00,
                'discount' => 0.00,
                'tax' => 18.00,
                'shipping' => 0.00,
                'payable' => 118.00,
                'wallet_tender' => 0.00,
                'gateway_due' => 118.00,
            ],
        ];

        foreach ($overrides as $key => $value) {
            $snapshot->{$key} = $value;
        }

        $snapshot->saveOrFail();

        return $snapshot;
    }

    private function buildWebhookCall(string $type, array $object): ReceiverWebhookCall
    {
        $event = [
            'id' => 'evt_' . uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => array_merge(['object' => 'payment_intent'], $object)],
        ];

        $raw = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $raw, self::WEBHOOK_SECRET);
        $sigHeader = "t={$timestamp},v1={$signature}";

        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sigHeader],
            $raw,
        );

        return new ProcessWebhookAttemptAction($this->receiver, $request)->execute();
    }

    public function testSweepVoidsCancelledStripeIntent(): void
    {
        $intentId = 'pi_recovery_canceled_' . uniqid();
        $snapshot = $this->makeSnapshot([
            'payment_intent_id' => $intentId,
            'gateway_attempt_status' => GatewayAttemptStatusEnum::PENDING_GATEWAY->value,
        ]);

        $call = $this->buildWebhookCall('payment_intent.canceled', [
            'id' => $intentId,
            'status' => 'canceled',
        ]);

        $result = new StripeOrderPaymentWebhookJob($call)->handle();

        $this->assertSame('snapshot voided and holds released', $result['message']);

        $snapshot->refresh();

        $this->assertSame(GatewayAttemptStatusEnum::VOIDED->value, $snapshot->gateway_attempt_status);
    }

    public function testVoidedSnapshotReleasesHeldCredit(): void
    {
        $intentId = 'pi_recovery_credit_' . uniqid();
        $user = auth()->user();

        $snapshot = $this->makeSnapshot([
            'payment_intent_id' => $intentId,
            'gateway_attempt_status' => GatewayAttemptStatusEnum::PENDING_GATEWAY->value,
        ]);

        $reservation = CreditReservation::create([
            'snapshot_id' => $snapshot->id,
            'companies_id' => $user->getCurrentCompany()->getId(),
            'discount_id' => 1,
            'amount' => 10.00,
            'status' => 'held',
        ]);

        $payload = $snapshot->checkout_payload;
        $payload['credit_reservation_id'] = $reservation->getId();
        $snapshot->checkout_payload = $payload;
        $snapshot->save();

        $call = $this->buildWebhookCall('payment_intent.canceled', [
            'id' => $intentId,
            'status' => 'canceled',
        ]);

        new StripeOrderPaymentWebhookJob($call)->handle();

        $reservation->refresh();

        $this->assertSame('released', $reservation->status);
    }

    public function testCleanupPendingEscalatesAfterMaxRetries(): void
    {
        $snapshot = $this->makeSnapshot([
            'gateway_attempt_status' => GatewayAttemptStatusEnum::CLEANUP_PENDING->value,
            'metadata' => ['reconcile_retries' => 3],
        ]);

        OrderTaxSnapshot::query()
            ->where('id', $snapshot->id)
            ->update(['updated_at' => now()->subMinutes(35)]);

        $this->artisan('souk:checkout-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();

        $this->assertSame(
            GatewayAttemptStatusEnum::NEEDS_RECONCILE->value,
            $snapshot->fresh()->gateway_attempt_status
        );
    }

    public function testCleanupPendingWithFewRetriesIsReleasedNotEscalated(): void
    {
        $snapshot = $this->makeSnapshot([
            'gateway_attempt_status' => GatewayAttemptStatusEnum::CLEANUP_PENDING->value,
            'metadata' => ['reconcile_retries' => 0],
        ]);

        OrderTaxSnapshot::query()
            ->where('id', $snapshot->id)
            ->update(['updated_at' => now()->subMinutes(35)]);

        $this->artisan('souk:checkout-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();

        $fresh = $snapshot->fresh();

        $this->assertNotSame(GatewayAttemptStatusEnum::NEEDS_RECONCILE->value, $fresh->gateway_attempt_status);
    }

    public function testAtomicFailureOnCancelledSetsCleanupPending(): void
    {
        $intentId = 'pi_txn_fail_recovery_' . uniqid();
        $snapshot = $this->makeSnapshot([
            'payment_intent_id' => $intentId,
            'gateway_attempt_status' => GatewayAttemptStatusEnum::PENDING_GATEWAY->value,
        ]);

        $call = $this->buildWebhookCall('payment_intent.canceled', [
            'id' => $intentId,
            'status' => 'canceled',
        ]);

        $job = new class ($call) extends StripeOrderPaymentWebhookJob {
            protected function buildReleaseAction(OrderTaxSnapshot $snapshot): ReleaseCheckoutHoldsAction
            {
                return new class ($snapshot) extends ReleaseCheckoutHoldsAction {
                    public function execute(): void
                    {
                        throw new RuntimeException('Simulated failure');
                    }
                };
            }
        };

        $result = $job->handle();

        $this->assertSame('void failed, marked cleanup_pending', $result['message']);

        $snapshot->refresh();

        $this->assertSame(GatewayAttemptStatusEnum::CLEANUP_PENDING->value, $snapshot->gateway_attempt_status);
    }

    public function testReconcileCommandSucceedsForAppWithNoStaleSnapshots(): void
    {
        $this->artisan('souk:checkout-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();
    }
}
