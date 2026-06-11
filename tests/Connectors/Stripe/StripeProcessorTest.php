<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Contracts\ThreeDSProcessorInterface;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Stripe\StripeProcessor;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Mockery;
use Stripe\Exception\InvalidRequestException as StripeInvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

class StripeProcessorTest extends TestCase
{
    use DatabaseTransactions;

    /** Roll back the commerce-connection rows the refund tests persist. */
    protected $connectionsToTransact = ['commerce'];

    protected Apps $kanvasApp;
    protected Companies $company;
    protected Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = static::$cachedUser;
        $this->company = $this->kanvasUser->getCurrentCompany();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function processor(FakeStripeClient $client): StripeProcessor
    {
        return new StripeProcessor($this->kanvasApp, $this->company, $client);
    }

    private function fakeIntent(string $id, string $status, array $extra = []): PaymentIntent
    {
        return PaymentIntent::constructFrom(array_merge([
            'id' => $id,
            'object' => 'payment_intent',
            'status' => $status,
        ], $extra));
    }

    private function stripeError(string $message, int $status = 400): StripeInvalidRequestException
    {
        return StripeInvalidRequestException::factory($message, $status, null, null, null, null);
    }

    private function buildPaymentMock(
        string $status = 'pending',
        ?string $intentId = null,
        ?string $idempotencyKey = 'aaaabbbb-cccc-4000-8000-ddddeeeeffff',
        array $pmMetadata = ['stripe_customer_id' => 'cus_1', 'stripe_payment_method_id' => 'pm_1'],
    ): Payments {
        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->exists = true;
        $payment->id = 1;
        $payment->status = $status;
        $payment->payment_intent_id = $intentId;
        $payment->idempotency_key = $idempotencyKey;
        $payment->amount = 100.00;
        $payment->currency = 'USD';
        $payment->users_id = $this->kanvasUser->getId();
        $payment->metadata = ['data' => []];

        $payment->shouldReceive('getId')->andReturn(1)->byDefault();

        $paymentMethod = $this->buildPaymentMethodMock($pmMetadata);
        $payment->shouldReceive('getAttribute')->with('paymentMethod')->andReturn($paymentMethod)->byDefault();
        $payment->shouldReceive('getRelationValue')->with('paymentMethod')->andReturn($paymentMethod)->byDefault();

        $payment->shouldReceive('save')->andReturnSelf()->byDefault();
        $payment->shouldReceive('saveQuietly')->andReturnSelf()->byDefault();
        $payment->shouldReceive('addLog')->andReturnNull()->byDefault();

        $payment->shouldReceive('addMetadata')
            ->andReturnUsing(function (array $data) use ($payment) {
                $existing = $payment->metadata ?? [];
                $payment->metadata = [
                    ...$existing,
                    ...$data,
                    'data' => [
                        ...($existing['data'] ?? []),
                        ...($data['data'] ?? []),
                    ],
                ];
            })
            ->byDefault();

        $payment->shouldReceive('getMetadata')
            ->andReturnUsing(fn (string $key) => $payment->metadata['data'][$key] ?? null)
            ->byDefault();

        $payment->shouldReceive('markAsPaid')
            ->andReturnUsing(function (array $metadata = []) use ($payment) {
                $payment->status = PaymentStatusEnum::PAID->value;
                $existing = $payment->metadata ?? [];
                $payment->metadata = [
                    ...$existing,
                    'data' => [
                        ...($existing['data'] ?? []),
                        ...($metadata['data'] ?? []),
                    ],
                ];
            })
            ->byDefault();

        return $payment;
    }

    private function buildPaymentMethodMock(array $metadata): PaymentMethods
    {
        $pm = Mockery::mock(PaymentMethods::class)->makePartial();
        $pm->exists = true;
        $pm->id = 1;
        $pm->metadata = $metadata;
        $pm->stripe_card_id = $metadata['stripe_payment_method_id'] ?? null;

        $pm->shouldReceive('getMetadata')
            ->andReturnUsing(fn (string $key) => $pm->metadata[$key] ?? null)
            ->byDefault();

        return $pm;
    }

    private function buildOrderMock(string $currency = 'USD', float $total = 100.00): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->exists = true;
        $order->id = 1;
        $order->currency = $currency;
        $order->total_gross_amount = $total;
        $order->total_net_amount = $total;

        $order->shouldReceive('getId')->andReturn(1)->byDefault();
        $order->shouldReceive('getTotalAmount')->andReturn($total)->byDefault();

        return $order;
    }

    public function testNameAndInterfaces(): void
    {
        $processor = $this->processor(new FakeStripeClient());

        $this->assertSame('stripe', $processor->name());
        $this->assertInstanceOf(PaymentProcessorInterface::class, $processor);
        $this->assertInstanceOf(ThreeDSProcessorInterface::class, $processor);
    }

    public function testAuthorizeSucceededMarksPaid(): void
    {
        $payment = $this->buildPaymentMock();
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('create', $this->fakeIntent('pi_1', 'succeeded', [
            'latest_charge' => 'ch_1',
        ]));

        $result = $this->processor($client)->authorize($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID, $result->paymentStatus);
        $this->assertSame('pi_1', $result->transactionId);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertSame('ch_1', $payment->authorization_code);
        $this->assertSame('ch_1', $payment->metadata['data']['stripe_charge_id']);

        $createCalls = $client->getPaymentIntents()->getCalls('create');
        $this->assertCount(1, $createCalls);
        $this->assertSame(10000, $createCalls[0]['params']['amount']);
        $this->assertSame('usd', $createCalls[0]['params']['currency']);
        $this->assertSame('cus_1', $createCalls[0]['params']['customer']);
        $this->assertSame('pm_1', $createCalls[0]['params']['payment_method']);
    }

    public function testAuthorizeRequiresActionReturnsClientSecret(): void
    {
        $payment = $this->buildPaymentMock();
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('create', $this->fakeIntent('pi_2', 'requires_action', [
            'client_secret' => 'cs_fake_pi_2',
        ]));

        $result = $this->processor($client)->authorize($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION, $result->paymentStatus);
        $this->assertSame('cs_fake_pi_2', $result->data['client_secret']);
        $this->assertSame('pi_2', $result->data['payment_intent_id']);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
        $this->assertSame('cs_fake_pi_2', $payment->metadata['data']['stripe_client_secret']);
    }

    public function testAuthorizeApiErrorMarksFailed(): void
    {
        $payment = $this->buildPaymentMock();
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('create', $this->stripeError('Your card was declined.'));

        $result = $this->processor($client)->authorize($payment, $order);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED, $result->paymentStatus);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertSame('Your card was declined.', $payment->metadata['data']['stripe_error']);
    }

    public function testAuthorizeWithExistingIntentRetrievesInsteadOfCreating(): void
    {
        $payment = $this->buildPaymentMock(intentId: 'pi_existing');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('retrieve', $this->fakeIntent('pi_existing', 'succeeded', [
            'latest_charge' => 'ch_existing',
        ]));

        $result = $this->processor($client)->authorize($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID, $result->paymentStatus);
        $this->assertCount(0, $client->getPaymentIntents()->getCalls('create'));
        $this->assertCount(1, $client->getPaymentIntents()->getCalls('retrieve'));
    }

    public function testAuthorizeSynthesizesIdempotencyKeyWhenNull(): void
    {
        $payment = $this->buildPaymentMock(idempotencyKey: null);
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('create', $this->fakeIntent('pi_idem', 'succeeded', [
            'latest_charge' => 'ch_idem',
        ]));

        $this->processor($client)->authorize($payment, $order);

        $createCalls = $client->getPaymentIntents()->getCalls('create');
        $this->assertSame('pi:payment:1', $createCalls[0]['options']['idempotency_key']);
    }

    public function testAuthorizeUsesProvidedIdempotencyKey(): void
    {
        $payment = $this->buildPaymentMock(idempotencyKey: 'my-key-123');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('create', $this->fakeIntent('pi_k', 'succeeded', [
            'latest_charge' => 'ch_k',
        ]));

        $this->processor($client)->authorize($payment, $order);

        $createCalls = $client->getPaymentIntents()->getCalls('create');
        $this->assertSame('my-key-123', $createCalls[0]['options']['idempotency_key']);
    }

    public function testAuthorizeOnAlreadyPaidPaymentDoesNotCharge(): void
    {
        $payment = $this->buildPaymentMock(status: PaymentStatusEnum::PAID->value, intentId: null);
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();

        $result = $this->processor($client)->authorize($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID, $result->paymentStatus);
        $this->assertCount(0, $client->getPaymentIntents()->getCalls('create'));
        $this->assertCount(0, $client->getPaymentIntents()->getCalls('retrieve'));
    }

    public function testRefundOnNonPaidPaymentIsRejected(): void
    {
        $payment = $this->buildPaymentMock(status: PaymentStatusEnum::CANCELLED->value, intentId: 'pi_x');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();

        $result = $this->processor($client)->refund($payment, $order);

        $this->assertFalse($result->success);
        $this->assertCount(0, $client->getRefunds()->getCalls('create'));
    }

    public function testAuthorizeRejectsUnsupportedCurrency(): void
    {
        $payment = $this->buildPaymentMock();
        $order = $this->buildOrderMock(currency: 'XYZ');

        $this->expectException(ValidationException::class);

        $this->processor(new FakeStripeClient())->authorize($payment, $order);
    }

    public function testCaptureCapturesAuthorizedIntent(): void
    {
        $payment = $this->buildPaymentMock(status: PaymentStatusEnum::AUTHORIZED->value, intentId: 'pi_cap');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('retrieve', $this->fakeIntent('pi_cap', 'requires_capture'));
        $client->getPaymentIntents()->queueResponse('capture', $this->fakeIntent('pi_cap', 'succeeded', [
            'latest_charge' => 'ch_cap',
        ]));

        $result = $this->processor($client)->capture($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertCount(1, $client->getPaymentIntents()->getCalls('capture'));
    }

    public function testCaptureOnAlreadySucceededIsNoop(): void
    {
        $payment = $this->buildPaymentMock(status: PaymentStatusEnum::PAID->value, intentId: 'pi_done');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('retrieve', $this->fakeIntent('pi_done', 'succeeded'));

        $result = $this->processor($client)->capture($payment, $order);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('already captured', strtolower($result->message));
        $this->assertCount(0, $client->getPaymentIntents()->getCalls('capture'));
    }

    public function testCaptureApiErrorReturnsFailure(): void
    {
        $payment = $this->buildPaymentMock(status: PaymentStatusEnum::AUTHORIZED->value, intentId: 'pi_caperr');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('retrieve', $this->stripeError('intent not found', 404));

        $result = $this->processor($client)->capture($payment, $order);

        $this->assertFalse($result->success);
    }

    public function testVoidCancelsUncapturedIntent(): void
    {
        $payment = $this->buildPaymentMock(status: PaymentStatusEnum::AUTHORIZED->value, intentId: 'pi_void');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('retrieve', $this->fakeIntent('pi_void', 'requires_capture'));
        $client->getPaymentIntents()->queueResponse('cancel', $this->fakeIntent('pi_void', 'canceled'));

        $result = $this->processor($client)->void($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->status);
        $this->assertCount(1, $client->getPaymentIntents()->getCalls('cancel'));
    }

    public function testVoidOnCapturedThrowsDomainException(): void
    {
        $payment = $this->buildPaymentMock(status: PaymentStatusEnum::PAID->value, intentId: 'pi_paid');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('retrieve', $this->fakeIntent('pi_paid', 'succeeded'));

        $this->expectException(DomainException::class);

        $this->processor($client)->void($payment, $order);
    }

    public function testVerifyMapsCurrentStatus(): void
    {
        $payment = $this->buildPaymentMock(status: PaymentStatusEnum::PENDING->value, intentId: 'pi_ver');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('retrieve', $this->fakeIntent('pi_ver', 'succeeded', [
            'latest_charge' => 'ch_ver',
        ]));

        $result = $this->processor($client)->verify($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame('succeeded', $result->isoCode);
        $this->assertSame(PaymentStatusEnum::PAID->value, $result->responseCode);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testStartChallengeReturnsClientSecretOnRequiresAction(): void
    {
        $payment = $this->buildPaymentMock(status: PaymentStatusEnum::PENDING_AUTHORIZATION->value, intentId: 'pi_3ds');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('retrieve', $this->fakeIntent('pi_3ds', 'requires_action', [
            'client_secret' => 'cs_fake_pi_3ds',
        ]));

        $result = $this->processor($client)->startChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $result->status);
        $this->assertSame('cs_fake_pi_3ds', $result->data['client_secret']);
    }

    public function testStartChallengeWithoutIntentReturnsEmptySecret(): void
    {
        $payment = $this->buildPaymentMock(intentId: null);
        $order = $this->buildOrderMock();

        $result = $this->processor(new FakeStripeClient())->startChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame('', $result->data['client_secret']);
    }

    public function testFinalizeChallengeMarksPaidAndIsIdempotent(): void
    {
        $payment = $this->buildPaymentMock(status: PaymentStatusEnum::PENDING_AUTHORIZATION->value, intentId: 'pi_fin');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getPaymentIntents()->queueResponse('retrieve', $this->fakeIntent('pi_fin', 'succeeded', [
            'latest_charge' => 'ch_fin',
        ]));

        $first = $this->processor($client)->finalizeChallenge($payment, $order);
        $this->assertTrue($first->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);

        // Second call: payment is terminal, short-circuits without another retrieve.
        $second = $this->processor($client)->finalizeChallenge($payment, $order);
        $this->assertTrue($second->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertCount(1, $client->getPaymentIntents()->getCalls('retrieve'));
    }

    public function testRefundFullMarksReversed(): void
    {
        $payment = $this->persistPayment(100.00, 'pi_ref_full', 'ch_ref_full');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getRefunds()->queueResponse('create', Refund::constructFrom([
            'id' => 're_full',
            'object' => 'refund',
            'status' => 'succeeded',
        ]));

        $result = $this->processor($client)->refund($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame('re_full', $result->transactionId);

        $refund = PaymentRefund::where('payments_id', $payment->getId())->first();
        $this->assertNotNull($refund);
        $this->assertSame(RefundStatusEnum::COMPLETED->value, $refund->status);
        $this->assertSame('re_full', $refund->processor_refund_id);

        $createCalls = $client->getRefunds()->getCalls('create');
        $this->assertSame('ch_ref_full', $createCalls[0]['params']['charge']);
        $this->assertSame(10000, $createCalls[0]['params']['amount']);
        $this->assertSame('refund:' . $payment->getId() . ':10000', $createCalls[0]['options']['idempotency_key']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
    }

    public function testRefundPartialKeepsPaid(): void
    {
        $payment = $this->persistPayment(100.00, 'pi_ref_part', 'ch_ref_part');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getRefunds()->queueResponse('create', Refund::constructFrom([
            'id' => 're_part',
            'object' => 'refund',
            'status' => 'succeeded',
        ]));

        $result = $this->processor($client)->refund($payment, $order, 40.00);

        $this->assertTrue($result->success);

        $refund = PaymentRefund::where('payments_id', $payment->getId())->first();
        $this->assertSame(RefundStatusEnum::COMPLETED->value, $refund->status);
        $this->assertEquals(40.00, (float) $refund->amount);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testRefundApiErrorMarksRefundFailed(): void
    {
        $payment = $this->persistPayment(100.00, 'pi_ref_err', 'ch_ref_err');
        $order = $this->buildOrderMock();

        $client = new FakeStripeClient();
        $client->getRefunds()->queueResponse('create', $this->stripeError('refund failed'));

        $result = $this->processor($client)->refund($payment, $order);

        $this->assertFalse($result->success);

        $refund = PaymentRefund::where('payments_id', $payment->getId())->first();
        $this->assertNotNull($refund);
        $this->assertSame(RefundStatusEnum::FAILED->value, $refund->status);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    private function persistPayment(float $amount, string $intentId, string $chargeId): Payments
    {
        $payment = new Payments();
        $payment->apps_id = $this->kanvasApp->getId();
        $payment->companies_id = $this->company->getId();
        $payment->users_id = $this->kanvasUser->getId();
        $payment->payment_methods_id = 0;
        $payment->payable_id = 0;
        $payment->payable_type = Order::class;
        $payment->concept = 'Stripe test payment';
        $payment->payment_date = date('Y-m-d');
        $payment->amount = $amount;
        $payment->currency = 'USD';
        $payment->status = PaymentStatusEnum::PAID->value;
        $payment->processor = 'stripe';
        $payment->payment_intent_id = $intentId;
        $payment->metadata = ['data' => ['stripe_charge_id' => $chargeId]];
        $payment->saveOrFail();

        return $payment;
    }
}
