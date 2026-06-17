<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Webhooks\StripeOrderPaymentWebhookJob;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;
use Stripe\Exception\SignatureVerificationException;
use Tests\TestCase;

class StripeOrderPaymentWebhookJobTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['ecosystem', 'commerce', 'workflow'];

    private const SECRET = 'whsec_test_secret_key';

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

    public function testPaymentIntentSucceededMarksPaid(): void
    {
        $payment = $this->createPayment(PaymentStatusEnum::PENDING->value, 'pi_wh_ok');

        $result = $this->dispatchEvent('payment_intent.succeeded', [
            'id' => 'pi_wh_ok',
            'status' => 'succeeded',
            'latest_charge' => 'ch_wh_ok',
            'metadata' => ['kanvas_company_id' => (string) $this->company->getId()],
        ]);

        $this->assertSame('payment marked paid', $result['message']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertSame('ch_wh_ok', $payment->authorization_code);
        $this->assertSame('ch_wh_ok', $payment->getMetadata('stripe_charge_id'));
    }

    public function testSignatureVerificationFailsOnReserialisedPayload(): void
    {
        $event = $this->buildEvent('payment_intent.succeeded', ['id' => 'pi_x', 'status' => 'succeeded']);
        $rawBytes = json_encode($event);
        $signature = $this->stripeSignature($rawBytes);

        // Store a re-serialised body (extra whitespace) but the signature computed over the
        // original bytes — this is exactly what breaks without P1's raw_payload column.
        $reserialised = json_encode($event, JSON_PRETTY_PRINT);

        $call = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $this->receiver->getId(),
            'url' => 'https://localhost/webhook',
            'headers' => ['stripe-signature' => [$signature]],
            'payload' => $event,
            'raw_payload' => $reserialised,
        ]);

        $this->expectException(SignatureVerificationException::class);

        new StripeOrderPaymentWebhookJob($call)->execute();
    }

    public function testValidSignatureOverRawPayloadVerifies(): void
    {
        $this->createPayment(PaymentStatusEnum::PENDING->value, 'pi_wh_sig');

        // Round-trips through ProcessWebhookAttemptAction → proves raw_payload is stored verbatim.
        $result = $this->dispatchEvent('payment_intent.succeeded', [
            'id' => 'pi_wh_sig',
            'status' => 'succeeded',
            'latest_charge' => 'ch_wh_sig',
        ]);

        $this->assertSame('payment marked paid', $result['message']);
    }

    public function testTerminalPaymentReDeliveryIsNoop(): void
    {
        $payment = $this->createPayment(PaymentStatusEnum::PAID->value, 'pi_wh_term');

        $result = $this->dispatchEvent('payment_intent.succeeded', [
            'id' => 'pi_wh_term',
            'status' => 'succeeded',
            'latest_charge' => 'ch_wh_term',
        ]);

        $this->assertSame('payment already terminal', $result['message']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testCompanyMismatchIsRejected(): void
    {
        $payment = $this->createPayment(PaymentStatusEnum::PENDING->value, 'pi_wh_mismatch');

        $result = $this->dispatchEvent('payment_intent.succeeded', [
            'id' => 'pi_wh_mismatch',
            'status' => 'succeeded',
            'latest_charge' => 'ch_wh_mismatch',
            'metadata' => ['kanvas_company_id' => (string) ($this->company->getId() + 999)],
        ]);

        $this->assertSame('company mismatch', $result['message']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PENDING->value, $payment->status);
    }

    public function testPaymentNotFoundReturnsMessageWithoutThrowing(): void
    {
        $result = $this->dispatchEvent('payment_intent.succeeded', [
            'id' => 'pi_does_not_exist',
            'status' => 'succeeded',
        ]);

        $this->assertSame('payment not found', $result['message']);
    }

    public function testPaymentIntentFailedMarksFailed(): void
    {
        $payment = $this->createPayment(PaymentStatusEnum::PROCESSING->value, 'pi_wh_fail');

        $result = $this->dispatchEvent('payment_intent.payment_failed', [
            'id' => 'pi_wh_fail',
            'status' => 'requires_payment_method',
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ]);

        $this->assertSame('payment marked failed', $result['message']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame('Your card was declined.', $payment->getMetadata('stripe_error'));
    }

    public function testChargeRefundedRecordsRefundAndReverses(): void
    {
        $payment = $this->createPayment(PaymentStatusEnum::PAID->value, 'pi_wh_cref', 'ch_wh_cref', 100.00);

        $result = $this->dispatchEvent('charge.refunded', [
            'id' => 'ch_wh_cref',
            'amount_refunded' => 10000,
            'refunds' => [
                'object' => 'list',
                'data' => [
                    ['id' => 're_wh_cref', 'amount' => 10000, 'status' => 'succeeded'],
                ],
            ],
        ]);

        $this->assertSame('refund recorded', $result['message']);

        $refund = PaymentRefund::where('payments_id', $payment->getId())->first();
        $this->assertNotNull($refund);
        $this->assertSame(RefundStatusEnum::COMPLETED->value, $refund->status);
        $this->assertSame('re_wh_cref', $refund->processor_refund_id);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
    }

    public function testRefundCreatedRecordsRefundAndIsIdempotent(): void
    {
        $payment = $this->createPayment(PaymentStatusEnum::PAID->value, 'pi_wh_rcre', 'ch_wh_rcre', 100.00);

        $object = [
            'id' => 're_wh_rcre',
            'charge' => 'ch_wh_rcre',
            'amount' => 10000,
            'status' => 'succeeded',
        ];

        $first = $this->dispatchEvent('refund.created', $object);
        $this->assertSame('refund recorded', $first['message']);

        // Re-delivery of the same refund id must not create a second row.
        $second = $this->dispatchEvent('refund.created', $object);
        $this->assertSame('refund already recorded', $second['message']);

        $this->assertSame(1, PaymentRefund::where('payments_id', $payment->getId())->count());

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
    }

    public function testUnhandledEventTypeIsIgnored(): void
    {
        $result = $this->dispatchEvent('payment_intent.created', ['id' => 'pi_ignored', 'status' => 'requires_confirmation']);

        $this->assertStringContainsString('Ignored event', $result['message']);
    }

    private function dispatchEvent(string $type, array $object): array
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

        $call = new ProcessWebhookAttemptAction($this->receiver, $request)->execute();

        return new StripeOrderPaymentWebhookJob($call)->handle();
    }

    private function buildEvent(string $type, array $object): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => array_merge(['object' => 'payment_intent'], $object)],
        ];
    }

    private function stripeSignature(string $payload, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, self::SECRET);

        return "t={$timestamp},v1={$signature}";
    }

    private function createPayment(
        string $status,
        string $intentId,
        ?string $chargeId = null,
        float $amount = 50.00,
    ): Payments {
        $payment = new Payments();
        $payment->apps_id = $this->kanvasApp->getId();
        $payment->companies_id = $this->company->getId();
        $payment->users_id = $this->kanvasUser->getId();
        $payment->payment_methods_id = 0;
        $payment->payable_id = 0;
        $payment->payable_type = Order::class;
        $payment->concept = 'Stripe webhook test';
        $payment->payment_date = now()->toDateString();
        $payment->amount = $amount;
        $payment->currency = 'USD';
        $payment->status = $status;
        $payment->processor = 'stripe';
        $payment->payment_intent_id = $intentId;
        $payment->authorization_code = $chargeId;
        $payment->metadata = $chargeId ? ['data' => ['stripe_charge_id' => $chargeId]] : [];
        $payment->saveOrFail();

        return $payment;
    }
}
