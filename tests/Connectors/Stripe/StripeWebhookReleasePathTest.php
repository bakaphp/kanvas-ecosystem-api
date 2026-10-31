<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Webhooks\StripeOrderPaymentWebhookJob;
use Kanvas\Souk\Taxes\Actions\ReleaseCheckoutHoldsAction;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Models\CreditReservation;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use RuntimeException;
use Tests\TestCase;

class StripeWebhookReleasePathTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['ecosystem', 'commerce', 'workflow'];

    private const string SECRET = 'whsec_release_test_key';

    private Apps $kanvasApp;
    private Companies $company;
    private Users $kanvasUser;
    private ReceiverWebhook $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = static::$cachedUser;
        $this->company = $this->kanvasUser->getCurrentCompany();
        $this->company->set(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value, self::SECRET);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => StripeOrderPaymentWebhookJob::class],
            ['name' => 'StripeOrderPaymentWebhookJob'],
        );

        $this->receiver = ReceiverWebhook::factory()
            ->app($this->kanvasApp->getId())
            ->user($this->kanvasUser->getId())
            ->company($this->company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [],
            ]);
    }

    public function testPaymentIntentCanceledVoidsSnapshotAndReleasesHolds(): void
    {
        $intentId = 'pi_canceled_' . uniqid();
        $snapshot = $this->buildSnapshot($intentId, withCredit: true);

        $result = $this->dispatchEvent('payment_intent.canceled', [
            'id' => $intentId,
            'status' => 'canceled',
        ]);

        $this->assertSame('snapshot voided and holds released', $result['message']);
        $this->assertSame($snapshot->uuid, $result['snapshot_uuid']);

        $snapshot->refresh();
        $this->assertSame(GatewayAttemptStatusEnum::VOIDED->value, $snapshot->gateway_attempt_status);

        $reservation = CreditReservation::where('snapshot_id', $snapshot->id)->first();
        $this->assertNotNull($reservation);
        $this->assertSame('released', $reservation->status);
    }

    public function testCheckoutSessionExpiredVoidsSnapshot(): void
    {
        $intentId = 'pi_session_exp_' . uniqid();
        $snapshot = $this->buildSnapshot($intentId);

        $result = $this->dispatchEvent('checkout.session.expired', [
            'id' => 'cs_expired_' . uniqid(),
            'object' => 'checkout.session',
            'payment_intent' => $intentId,
        ]);

        $this->assertSame('snapshot voided and holds released', $result['message']);

        $snapshot->refresh();
        $this->assertSame(GatewayAttemptStatusEnum::VOIDED->value, $snapshot->gateway_attempt_status);
    }

    public function testAtomicTransactionFailureSetsCleanupPending(): void
    {
        $intentId = 'pi_txn_fail_' . uniqid();
        $snapshot = $this->buildSnapshot($intentId);

        $call = $this->buildWebhookCall('payment_intent.canceled', ['id' => $intentId, 'status' => 'canceled']);

        $job = new class ($call) extends StripeOrderPaymentWebhookJob {
            protected function buildReleaseAction(OrderTaxSnapshot $snapshot): ReleaseCheckoutHoldsAction
            {
                return new class ($snapshot) extends ReleaseCheckoutHoldsAction {
                    public function execute(): void
                    {
                        throw new RuntimeException('Simulated DB failure');
                    }
                };
            }
        };

        $result = $job->handle();

        $this->assertSame('void failed, marked cleanup_pending', $result['message']);

        $snapshot->refresh();
        $this->assertSame(GatewayAttemptStatusEnum::CLEANUP_PENDING->value, $snapshot->gateway_attempt_status);
    }

    public function testCanceledWithNoSnapshotReturnsNotFound(): void
    {
        $result = $this->dispatchEvent('payment_intent.canceled', [
            'id' => 'pi_no_snap_' . uniqid(),
            'status' => 'canceled',
        ]);

        $this->assertSame('no unattached snapshot found', $result['message']);
    }

    public function testSessionExpiredWithNoSnapshotReturnsNotFound(): void
    {
        $result = $this->dispatchEvent('checkout.session.expired', [
            'id' => 'cs_no_snap_' . uniqid(),
            'object' => 'checkout.session',
            'payment_intent' => 'pi_none_' . uniqid(),
        ]);

        $this->assertSame('no unattached snapshot found', $result['message']);
    }

    public function testAlreadyAttachedSnapshotIsIgnored(): void
    {
        $intentId = 'pi_attached_' . uniqid();

        $snapshot = $this->buildSnapshot($intentId);
        $snapshot->order_id = 1;
        $snapshot->save();

        $result = $this->dispatchEvent('payment_intent.canceled', [
            'id' => $intentId,
            'status' => 'canceled',
        ]);

        $this->assertSame('no unattached snapshot found', $result['message']);

        $snapshot->refresh();
        $this->assertNotSame(GatewayAttemptStatusEnum::VOIDED->value, $snapshot->gateway_attempt_status);
    }

    private function buildSnapshot(string $intentId, bool $withCredit = false): OrderTaxSnapshot
    {
        $user = auth()->user();

        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = null;
        $snapshot->payment_intent_id = $intentId;
        $snapshot->cart_session_id = 'cart-rel-' . uniqid();
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
        $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING_GATEWAY->value;
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->checkout_payload = [
            'lines' => [],
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
                'subtotal' => 100.0,
                'discount' => 0.0,
                'tax' => 18.0,
                'shipping' => 0.0,
                'payable' => 118.0,
                'wallet_tender' => 0.0,
                'gateway_due' => 118.0,
            ],
        ];
        $snapshot->saveOrFail();

        if ($withCredit) {
            $reservation = CreditReservation::create([
                'snapshot_id' => $snapshot->id,
                'companies_id' => $this->company->getId(),
                'discount_id' => 1,
                'amount' => 10.00,
                'status' => 'held',
            ]);

            $payload = $snapshot->checkout_payload;
            $payload['credit_reservation_id'] = $reservation->getId();
            $snapshot->checkout_payload = $payload;
            $snapshot->save();
        }

        return $snapshot;
    }

    private function buildWebhookCall(string $type, array $object): ReceiverWebhookCall
    {
        $event = $this->buildEvent($type, $object);
        $raw = json_encode($event);

        $request = Request::create(
            'https://localhost/v1/receiver/' . $this->receiver->uuid,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($raw)],
            $raw,
        );

        return new ProcessWebhookAttemptAction($this->receiver, $request)->execute();
    }

    private function dispatchEvent(string $type, array $object): array
    {
        $call = $this->buildWebhookCall($type, $object);

        return new StripeOrderPaymentWebhookJob($call)->handle();
    }

    private function buildEvent(string $type, array $object): array
    {
        $objectKey = str_contains($type, 'checkout.session') ? 'checkout.session' : 'payment_intent';

        return [
            'id' => 'evt_' . uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => array_merge(['object' => $objectKey], $object)],
        ];
    }

    private function stripeSignature(string $payload, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET);

        return "t={$timestamp},v1={$signature}";
    }
}
