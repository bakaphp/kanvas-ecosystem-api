<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Souk\Payments\Actions\LogPaymentEventAction;
use Kanvas\Souk\Payments\DataTransferObject\PaymentFailure;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCaseUnit;
use Throwable;

final class PortalPaymentProcessorErrorLogTest extends TestCaseUnit
{
    public function testReasonIsReadFromTheWrappedGatewayBody(): void
    {
        $exception = $this->decline(['errorInformation' => ['reason' => 'INSUFFICIENT_FUND']]);

        $this->assertSame('INSUFFICIENT_FUND', $exception->getReason());
    }

    public function testReasonIsReadFromAnUnwrappedGatewayBody(): void
    {
        $exception = new EchoPayException('Payment not approved', 400, null, [
            'errorInformation' => ['reason' => 'INVALID_ACCOUNT'],
        ]);

        $this->assertSame('INVALID_ACCOUNT', $exception->getReason());
    }

    public function testReasonIsNullWithoutErrorInformation(): void
    {
        $this->assertNull(new EchoPayException('Gateway timeout', 0)->getReason());
    }

    public function testDeclineLogsTheGatewayFieldsAsSent(): void
    {
        $exception = $this->decline([
            'errorInformation' => ['reason' => 'INSUFFICIENT_FUND', 'message' => 'Decline - Insufficient funds in the account.'],
            'processorInformation' => ['responseCode' => '51'],
            'paymentInsightsInformation' => ['responseInsights' => ['category' => 'ISSUER_CANNOT_APPROVE_AT_THIS_TIME']],
        ]);

        $this->assertPaymentErrorLogged($exception, new PaymentFailure(
            code: 'INSUFFICIENT_FUND',
            message: 'Decline - Insufficient funds in the account.',
            processorResponseCode: '51',
            responseInsight: 'ISSUER_CANNOT_APPROVE_AT_THIS_TIME',
        ));
    }

    public function testGatewayFailureWithoutBodyKeepsTheExceptionDetails(): void
    {
        $this->assertPaymentErrorLogged(
            new EchoPayException('Gateway timeout', 0),
            new PaymentFailure(code: 'EchoPayException', message: 'Gateway timeout')
        );
    }

    public function testResolvedMessageOverridesTheExceptionMessage(): void
    {
        $this->assertPaymentErrorLogged(
            new RuntimeException('Client error: 400'),
            new PaymentFailure(code: 'RuntimeException', message: 'Invalid card number'),
            'Invalid card number'
        );
    }

    private function decline(array $data): EchoPayException
    {
        return new EchoPayException('Payment not approved', 400, null, ['data' => $data]);
    }

    private function assertPaymentErrorLogged(Throwable $exception, PaymentFailure $expected, ?string $resolvedMessage = null): void
    {
        $payment = new Payments();
        $context = ['order_id' => 1];

        $action = Mockery::mock(LogPaymentEventAction::class);
        $action->shouldReceive('execute')
            ->once()
            ->with($payment, 'payment_error', $context, Mockery::on(fn (PaymentFailure $failure) => $failure == $expected));
        $this->app->instance(LogPaymentEventAction::class, $action);

        $processor = new ReflectionClass(PortalPaymentProcessor::class)->newInstanceWithoutConstructor();
        new ReflectionMethod($processor, 'logPaymentError')
            ->invoke($processor, $payment, $exception, $context, $resolvedMessage);
    }
}
