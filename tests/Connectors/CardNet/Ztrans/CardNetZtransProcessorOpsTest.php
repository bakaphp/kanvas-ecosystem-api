<?php

declare(strict_types=1);

namespace Tests\Connectors\CardNet\Ztrans;

use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\CardNet\Ztrans\Enums\ConfigurationEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\CardNetZtrans\CardNetZtransProcessor;
use Kanvas\Souk\Payments\Processors\ProcessorFactory;
use Mockery;
use Tests\Connectors\CardNet\Ztrans\Concerns\BuildsZtransFixtures;
use Tests\TestCase;

class CardNetZtransProcessorOpsTest extends TestCase
{
    use BuildsZtransFixtures;

    private CardNetZtransProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = $this->buildProcessor($this->fakeApp());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testCaptureChecksOutTheHeldTransaction(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/checkouts' => Http::response($this->approved('txToken', 'txn-out')),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'txn-hold';
        $payment->authorization_code = '010123';

        $result = $this->processor->capture($payment, $this->buildOrder(), 80.0);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $checkout = $this->sentTo('/transactions/checkouts')[0];
        $this->assertSame('txn-hold', $checkout['txToken']);
        $this->assertSame(80.0, $checkout['amount']);
        $this->assertSame(18.0, $checkout['itbis']);
        $this->assertSame('010123', $checkout['approval-code']);
    }

    public function testCaptureOfANonHeldPaymentMakesNoCall(): void
    {
        Http::fake();
        $payment = $this->buildPayment(PaymentStatusEnum::PAID->value);

        $result = $this->processor->capture($payment, $this->buildOrder());

        $this->assertTrue($result->success);
        Http::assertNothingSent();
    }

    public function testDeclinedCaptureKeepsThePaymentAuthorized(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/checkouts' => Http::response($this->declined('96', '1811')),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'txn-hold';

        $result = $this->processor->capture($payment, $this->buildOrder());

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
    }

    public function testVoidCancelsThePayment(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/voids' => Http::response($this->approved()),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'txn-100';

        $result = $this->processor->void($payment, $this->buildOrder());

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->status);
        $void = $this->sentTo('/transactions/voids')[0];
        $this->assertSame('txn-100', $void['pnRef']);
        $this->assertSame(100.0, $void['amount']);
    }

    public function testVoidAfterBatchCloseAsksForARefund(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/voids' => Http::response($this->declined('22', '1020')),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'txn-100';

        $result = $this->processor->void($payment, $this->buildOrder());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('use a refund', $result->message);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testVoidRejectsPaymentsThatAreNotSettled(): void
    {
        Http::fake();

        $result = $this->processor->void($this->buildPayment(PaymentStatusEnum::FAILED->value), $this->buildOrder());

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function testFullRefundReversesThePayment(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/refund' => Http::response($this->approved()),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'txn-100';

        $result = $this->processor->refund($payment, $this->buildOrder());

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
        $refund = $this->sentTo('/transactions/refund')[0];
        $this->assertSame('refund', $refund['Operation']);
        $this->assertSame('txn-100', $refund['txToken']);
    }

    public function testRefundAndVoidOfADollarOrderSendTheUsdCode(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/refund' => Http::response($this->approved()),
            '*/transactions/voids' => Http::response($this->approved()),
        ]);
        $refunded = $this->buildPayment(PaymentStatusEnum::PAID->value);
        $refunded->payment_intent_id = 'txn-100';
        $voided = $this->buildPayment(PaymentStatusEnum::PAID->value);
        $voided->payment_intent_id = 'txn-200';

        $this->processor->refund($refunded, $this->buildOrder('USD'));
        $this->processor->void($voided, $this->buildOrder('USD'));

        $this->assertSame('840', $this->sentTo('/transactions/refund')[0]['currency']);
        $this->assertSame('840', $this->sentTo('/transactions/voids')[0]['currency']);
    }

    public function testPartialRefundKeepsThePaymentPaid(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/refund' => Http::response($this->approved()),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'txn-100';

        $result = $this->processor->refund($payment, $this->buildOrder(), 40.0);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testRefundWithoutABearerTokenReportsTheMissingSetting(): void
    {
        Http::fake(['*/idenpotency-keys' => $this->idempotencyKeySequence()]);
        $processor = $this->buildProcessor($this->fakeApp([ConfigurationEnum::REFUND_BEARER_TOKEN->value => '']));
        $payment = $this->buildPayment(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'txn-100';

        $result = $processor->refund($payment, $this->buildOrder());

        $this->assertFalse($result->success);
        $this->assertStringContainsString(ConfigurationEnum::REFUND_BEARER_TOKEN->value, $result->message);
        $this->assertCount(0, $this->sentTo('/transactions/refund'));
    }

    public function testDeclinedRefundIsReportedAsAFailure(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/refund' => Http::response($this->declined('12', '1017')),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'txn-100';

        $result = $this->processor->refund($payment, $this->buildOrder());

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testUnreachableCaptureKeepsThePaymentAuthorizedAndReusesTheKey(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/checkouts' => Http::sequence()
                ->pushFailedConnection()
                ->push($this->approved('txToken', 'txn-out')),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'txn-hold';
        $order = $this->buildOrder();

        $first = $this->processor->capture($payment, $order);
        $second = $this->processor->capture($payment, $order);

        $this->assertFalse($first->success);
        $this->assertSame('Capture could not be completed. Try again.', $first->message);
        $this->assertTrue($second->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertCount(1, $this->sentTo('/idenpotency-keys'));
        $this->assertSame(['key-1', 'key-1'], array_map(
            fn (Request $request): string => $request['idempotency-key'],
            $this->sentTo('/transactions/checkouts')
        ));
    }

    public function testUnreachableIdempotencyKeyEndpointLeavesACaptureUntouched(): void
    {
        Http::fake([
            '*/idenpotency-keys' => Http::sequence()->pushFailedConnection(),
            '*/transactions/checkouts' => Http::response($this->approved('txToken', 'txn-out')),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::AUTHORIZED->value);
        $payment->payment_intent_id = 'txn-hold';

        $result = $this->processor->capture($payment, $this->buildOrder());

        $this->assertFalse($result->success);
        $this->assertSame('Capture could not be completed. Try again.', $result->message);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
        $this->assertNull($payment->getMetadata('ztrans_ikeys'));
        $this->assertCount(0, $this->sentTo('/transactions/checkouts'));
    }

    public function testUnreachableVoidKeepsThePaymentPaidAndReusesTheKey(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/voids' => Http::sequence()
                ->pushFailedConnection()
                ->push($this->approved()),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'txn-100';
        $order = $this->buildOrder();

        $first = $this->processor->void($payment, $order);

        $this->assertFalse($first->success);
        $this->assertSame('Void could not be completed. Try again.', $first->message);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);

        $second = $this->processor->void($payment, $order);

        $this->assertTrue($second->success);
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->status);
        $this->assertCount(1, $this->sentTo('/idenpotency-keys'));
        $this->assertSame(['key-1', 'key-1'], array_map(
            fn (Request $request): string => $request['idempotency-key'],
            $this->sentTo('/transactions/voids')
        ));
    }

    public function testUnreachableRefundKeepsThePaymentPaidAndReusesTheKey(): void
    {
        Http::fake([
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/refund' => Http::sequence()
                ->pushFailedConnection()
                ->push($this->approved()),
        ]);
        $payment = $this->buildPayment(PaymentStatusEnum::PAID->value);
        $payment->payment_intent_id = 'txn-100';
        $order = $this->buildOrder();

        $first = $this->processor->refund($payment, $order);

        $this->assertFalse($first->success);
        $this->assertSame('Refund could not be completed. Try again.', $first->message);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);

        $second = $this->processor->refund($payment, $order);

        $this->assertTrue($second->success);
        $this->assertSame(PaymentStatusEnum::REVERSED->value, $payment->status);
        $this->assertCount(1, $this->sentTo('/idenpotency-keys'));
        $this->assertSame(['key-1', 'key-1'], array_map(
            fn (Request $request): string => $request['idempotency-key'],
            $this->sentTo('/transactions/refund')
        ));
    }

    public function testVerifyAndPlainAuthorizeAreNotSupported(): void
    {
        $this->expectException(DomainException::class);

        $this->processor->verify($this->buildPayment(), $this->buildOrder());
    }

    public function testAuthorizeRequiresThe3DSEntryPoint(): void
    {
        $this->expectException(DomainException::class);

        $this->processor->authorize($this->buildPayment(), $this->buildOrder());
    }

    public function testFactoryResolvesTheProcessorByName(): void
    {
        $processor = ProcessorFactory::make('cardnet_ztrans', app(Apps::class), static::$cachedUser->getCurrentCompany());

        $this->assertInstanceOf(CardNetZtransProcessor::class, $processor);
        $this->assertSame('cardnet_ztrans', $processor->name());
    }
}
