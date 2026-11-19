<?php

declare(strict_types=1);

namespace Tests\Connectors\CardNet\Ztrans;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\CardNet\Ztrans\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\CardNetZtrans\CardNetZtransProcessor;
use Mockery;
use Tests\Connectors\CardNet\Ztrans\Concerns\BuildsZtransFixtures;
use Tests\TestCase;

class CardNetZtransProcessor3DSTest extends TestCase
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

    public function testFrictionlessAuthenticationRunsTheSaleAndMarksPaid(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationFrictionless()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/sales' => Http::response($this->approved()),
        ]);
        $payment = $this->buildPayment();

        $result = $this->processor->startChallenge($payment, $this->buildOrder(), ['card' => $this->card()]);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $result->status);
        $this->assertSame('txn-100', $payment->payment_intent_id);
        $this->assertSame('010123', $payment->authorization_code);

        $sale = $this->sentTo('/transactions/sales')[0];
        $this->assertSame('key-1', $sale['idempotency-key']);
        $this->assertSame(self::PAN, $sale['card-number']);
        $this->assertSame('12/27', $sale['expiration-date']);
        $this->assertSame('214', $sale['currency']);
        $this->assertSame('aaaabbbb-cccc-4000-8000-ddddeeeeffff', $sale['token']);
        $this->assertSame(100.0, $sale['amount']);
        $this->assertSame(2, $sale['tds_mode']);
        $this->assertSame('tds-1', $sale['tds_servertransactionid']);
        $this->assertSame('02', $sale['tds_eci']);
        $this->assertSame('aav-value', $sale['tds_avv']);
        $this->assertSame('2.1.0', $sale['tds_programprotocol']);
        $this->assertSame('ds-1', $sale['tds_dstransactionid']);
        $this->assertSame('Y', $sale['tds_status']);
        $this->assertNoCardDataStored($payment);
    }

    public function testAuthenticationRequestIsSignedAndCarriesTheAmountInMinorUnits(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationChallenge()),
        ]);
        $payment = $this->buildPayment();

        $this->processor->startChallenge($payment, $this->buildOrder(), ['card' => $this->card()]);

        $authentication = $this->sentTo('/server/authentication')[0];
        $this->assertSame('10000', $authentication['purchaseAmount']);
        $this->assertSame('2712', $authentication['cardExpiryDate']);
        $this->assertSame(self::PAN, $authentication['acctNumber']);
        $this->assertSame('https://api.test/v1/receiver/abc?payment_id=1', $authentication['urlRedirect']);
        $this->assertSame(
            hash('sha512', '1000000012712' . $authentication['integratorTxId'] . '10000test-api-key'),
            $authentication['signature']
        );
    }

    public function testFrictionlessAuthenticationWithHoldChecksInAndAuthorizes(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationFrictionless()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/checkins' => Http::response($this->approved('txToken', 'txn-hold')),
        ]);
        $payment = $this->buildPayment();

        $result = $this->processor->startChallenge(
            $payment,
            $this->buildOrder(),
            ['card' => $this->card(), 'use_hold' => true]
        );

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $result->status);
        $this->assertSame('txn-hold', $payment->payment_intent_id);
        $this->assertCount(1, $this->sentTo('/transactions/checkins'));
        $this->assertCount(0, $this->sentTo('/transactions/sales'));
    }

    public function testChallengeReturnsTheChallengeDataWithoutRunningTheSale(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationChallenge()),
        ]);
        $payment = $this->buildPayment();

        $result = $this->processor->startChallenge($payment, $this->buildOrder(), ['card' => $this->card()]);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $result->status);
        $this->assertSame('tds-1', $result->data['challenge_token']);
        $this->assertStringContainsString('/authentication/load', $result->data['challenge_url']);
        $this->assertStringContainsString('action="' . $result->data['challenge_url'] . '"', $result->data['challenge_form']);
        $this->assertStringContainsString('name="browserChallengeToken" value="tds-1"', $result->data['challenge_form']);
        $this->assertStringContainsString('document.forms[0].submit()', $result->data['challenge_form']);
        $this->assertCount(0, $this->sentTo('/transactions/sales'));
        $this->assertNoCardDataStored($payment);
    }

    public function testFinalizeAfterChallengeRunsTheSaleWithTheCardResentByTheClient(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationChallenge()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/sales' => Http::response($this->approved()),
        ]);
        $payment = $this->buildPayment();
        $order = $this->buildOrder();

        $this->processor->startChallenge($payment, $order, ['card' => $this->card()]);
        $result = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $result->status);

        $status = $this->sentTo('/server/status')[0];
        $this->assertSame('tds-1', $status['threeDSServerTransID']);
        $sale = $this->sentTo('/transactions/sales')[0];
        $this->assertSame(self::PAN, $sale['card-number']);
        $this->assertSame('tds-1', $sale['tds_servertransactionid']);
        $this->assertNoCardDataStored($payment);
    }

    public function testFinalizeWithoutCardKeepsThePaymentPendingAndCallsNothing(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationChallenge()),
        ]);
        $payment = $this->buildPayment();
        $order = $this->buildOrder();
        $this->processor->startChallenge($payment, $order, ['card' => $this->card()]);

        $result = $this->processor->finalizeChallenge($payment, $order, []);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $result->status);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
        $this->assertCount(0, $this->sentTo('/server/status'));
        $this->assertCount(0, $this->sentTo('/transactions/sales'));
    }

    public function testFinalizeFailsWhenTheChallengeWasNotPassed(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationChallenge()),
            '*/server/status' => Http::response(['transStatus' => 'N']),
        ]);
        $payment = $this->buildPayment();
        $order = $this->buildOrder();
        $this->processor->startChallenge($payment, $order, ['card' => $this->card()]);

        $result = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertCount(0, $this->sentTo('/transactions/sales'));
    }

    public function testRejectedAuthenticationFailsThePayment(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response(['threeDSServerTransID' => 'tds-1', 'transStatus' => 'R']),
        ]);
        $payment = $this->buildPayment();

        $result = $this->processor->startChallenge($payment, $this->buildOrder(), ['card' => $this->card()]);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $result->status);
        $this->assertSame('Card authentication failed.', $result->message);
    }

    public function testDeclinedSaleFailsWithACustomerSafeMessage(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationFrictionless()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/sales' => Http::response($this->declined('51', '1016')),
        ]);
        $payment = $this->buildPayment();

        $result = $this->processor->startChallenge($payment, $this->buildOrder(), ['card' => $this->card()]);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $result->status);
        $this->assertSame('Insufficient funds.', $result->message);
        $this->assertStringNotContainsString('1016', $result->message);
    }

    public function testThreeDSAuthenticationFailureCodeMapsToAuthenticationMessage(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationFrictionless()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/sales' => Http::response($this->declined('05', '4901')),
        ]);

        $result = $this->processor->startChallenge($this->buildPayment(), $this->buildOrder(), ['card' => $this->card()]);

        $this->assertSame('Card authentication failed.', $result->message);
    }

    public function testFinalizeOnAPaidPaymentIsANoOp(): void
    {
        Http::fake();
        $payment = $this->buildPayment(PaymentStatusEnum::PAID->value);

        $result = $this->processor->finalizeChallenge($payment, $this->buildOrder(), ['card' => $this->card()]);

        $this->assertTrue($result->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $result->status);
        Http::assertNothingSent();
    }

    public function testUnknownSaleOutcomeKeepsTheIdempotencyKeyForTheRetry(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationChallenge()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/sales' => Http::sequence()
                ->pushFailedConnection()
                ->push($this->approved()),
        ]);
        $payment = $this->buildPayment();
        $order = $this->buildOrder();
        $this->processor->startChallenge($payment, $order, ['card' => $this->card()]);

        $first = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertFalse($first->success);
        $this->assertSame(PaymentStatusEnum::PROCESSING->value, $first->status);
        $this->assertSame(PaymentStatusEnum::PROCESSING->value, $payment->status);
        $this->assertStringContainsString('Finalize this same payment again', $first->message);

        $second = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertTrue($second->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertCount(1, $this->sentTo('/idenpotency-keys'));
        $this->assertSame(['key-1', 'key-1'], array_map(
            fn (Request $request): string => $request['idempotency-key'],
            $this->sentTo('/transactions/sales')
        ));
    }

    public function testGatewayErrorPageOnTheSaleKeepsTheIdempotencyKeyForTheRetry(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationChallenge()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/sales' => Http::sequence()
                ->push('<html>502 Bad Gateway</html>', 502)
                ->push($this->approved()),
        ]);
        $payment = $this->buildPayment();
        $order = $this->buildOrder();
        $this->processor->startChallenge($payment, $order, ['card' => $this->card()]);

        $first = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertFalse($first->success);
        $this->assertSame(PaymentStatusEnum::PROCESSING->value, $payment->status);

        $second = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertTrue($second->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertSame(['key-1', 'key-1'], array_map(
            fn (Request $request): string => $request['idempotency-key'],
            $this->sentTo('/transactions/sales')
        ));
    }

    public function testUnknownCheckinOutcomeKeepsTheIdempotencyKeyForTheRetry(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationChallenge()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/checkins' => Http::sequence()
                ->pushFailedConnection()
                ->push($this->approved('txToken', 'txn-hold')),
        ]);
        $payment = $this->buildPayment();
        $order = $this->buildOrder();
        $this->processor->startChallenge($payment, $order, ['card' => $this->card(), 'use_hold' => true]);

        $first = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertFalse($first->success);
        $this->assertSame(PaymentStatusEnum::PROCESSING->value, $first->status);
        $this->assertSame(PaymentStatusEnum::PROCESSING->value, $payment->status);

        $second = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertTrue($second->success);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $payment->status);
        $this->assertCount(1, $this->sentTo('/idenpotency-keys'));
        $this->assertSame(['key-1', 'key-1'], array_map(
            fn (Request $request): string => $request['idempotency-key'],
            $this->sentTo('/transactions/checkins')
        ));
    }

    public function testUnreachableAuthenticationKeepsThePaymentPendingForARetry(): void
    {
        Http::fake([
            '*/server/authentication' => Http::sequence()
                ->pushFailedConnection()
                ->push($this->authenticationFrictionless()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/sales' => Http::response($this->approved()),
        ]);
        $payment = $this->buildPayment();
        $order = $this->buildOrder();

        $first = $this->processor->startChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertFalse($first->success);
        $this->assertSame(PaymentStatusEnum::PENDING->value, $first->status);
        $this->assertSame(PaymentStatusEnum::PENDING->value, $payment->status);
        $this->assertSame('Card authentication is unavailable. Try again.', $first->message);
        $this->assertCount(0, $this->sentTo('/idenpotency-keys'));

        $second = $this->processor->startChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertTrue($second->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertCount(1, $this->sentTo('/idenpotency-keys'));
        $this->assertCount(1, $this->sentTo('/transactions/sales'));
    }

    public function testUnreachableStatusCheckKeepsTheChallengeForARetry(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationChallenge()),
            '*/server/status' => Http::sequence()
                ->pushFailedConnection()
                ->push($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/sales' => Http::response($this->approved()),
        ]);
        $payment = $this->buildPayment();
        $order = $this->buildOrder();
        $this->processor->startChallenge($payment, $order, ['card' => $this->card()]);

        $first = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertFalse($first->success);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $first->status);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
        $this->assertSame('Card authentication status is unavailable. Try again.', $first->message);
        $this->assertCount(0, $this->sentTo('/idenpotency-keys'));
        $this->assertCount(0, $this->sentTo('/transactions/sales'));

        $second = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertTrue($second->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertCount(1, $this->sentTo('/idenpotency-keys'));
        $this->assertCount(1, $this->sentTo('/transactions/sales'));
    }

    public function testUnreachableIdempotencyKeyEndpointKeepsThePaymentPendingForARetry(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationChallenge()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => Http::sequence()
                ->pushFailedConnection()
                ->push('ikey:key-1'),
            '*/transactions/sales' => Http::response($this->approved()),
        ]);
        $payment = $this->buildPayment();
        $order = $this->buildOrder();
        $this->processor->startChallenge($payment, $order, ['card' => $this->card()]);

        $first = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertFalse($first->success);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $first->status);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
        $this->assertSame('Payment could not be processed. Try again.', $first->message);
        $this->assertNull($payment->getMetadata('ztrans_ikeys'));
        $this->assertCount(0, $this->sentTo('/transactions/sales'));

        $second = $this->processor->finalizeChallenge($payment, $order, ['card' => $this->card()]);

        $this->assertTrue($second->success);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertSame(['sale' => 'key-1'], $payment->getMetadata('ztrans_ikeys'));
        $this->assertSame(['key-1'], array_map(
            fn (Request $request): string => $request['idempotency-key'],
            $this->sentTo('/transactions/sales')
        ));
    }

    public function testFinalizeWithoutCardReportsTheRealStatus(): void
    {
        Http::fake();
        $payment = $this->buildPayment(PaymentStatusEnum::PROCESSING->value);

        $result = $this->processor->finalizeChallenge($payment, $this->buildOrder(), []);

        $this->assertFalse($result->success);
        $this->assertSame(PaymentStatusEnum::PROCESSING->value, $result->status);
        Http::assertNothingSent();
    }

    public function testADollarOrderSendsTheUsdCodeToThreeDSAndTheSale(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationFrictionless()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/sales' => Http::response($this->approved()),
        ]);

        $result = $this->processor->startChallenge($this->buildPayment(), $this->buildOrder('USD'), ['card' => $this->card()]);

        $this->assertTrue($result->success);
        $this->assertSame('840', $this->sentTo('/server/authentication')[0]['purchaseCurrency']);
        $this->assertSame('840', $this->sentTo('/transactions/sales')[0]['currency']);
    }

    public function testACurrencyZtransDoesNotSupportIsRejected(): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);

        $this->processor->startChallenge($this->buildPayment(), $this->buildOrder('EUR'), ['card' => $this->card()]);
    }

    public function testMissingCardIsRejectedAtStart(): void
    {
        Http::fake();

        $this->expectException(ValidationException::class);

        $this->processor->startChallenge($this->buildPayment(), $this->buildOrder(), []);
    }

    public function testUseHoldFallsBackToTheAppSetting(): void
    {
        Http::fake([
            '*/server/authentication' => Http::response($this->authenticationFrictionless()),
            '*/server/status' => Http::response($this->statusAuthenticated()),
            '*/idenpotency-keys' => $this->idempotencyKeySequence(),
            '*/transactions/checkins' => Http::response($this->approved('txToken', 'txn-hold')),
        ]);
        $processor = $this->buildProcessor($this->fakeApp([ConfigurationEnum::USE_HOLD->value => '1']));

        $result = $processor->startChallenge($this->buildPayment(), $this->buildOrder(), ['card' => $this->card()]);

        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $result->status);
    }
}
