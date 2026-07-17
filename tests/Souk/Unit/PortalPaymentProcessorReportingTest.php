<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCaseUnit;

final class PortalPaymentProcessorReportingTest extends TestCaseUnit
{
    /**
     * A card decline is the gateway answering with a 4xx ("Payment not approved") — it must NOT be
     * reported to Sentry (it's recorded in payment_logs). A missing/5xx response is a real gateway
     * failure and MUST be reported.
     */
    #[DataProvider('echoPayErrorProvider')]
    public function testGatewayFailureClassification(int $status, bool $expectedReported): void
    {
        $processor = new ReflectionClass(PortalPaymentProcessor::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($processor, 'isEchoPayGatewayFailure');

        $this->assertSame(
            $expectedReported,
            $method->invoke($processor, new EchoPayException('Payment not approved', $status)),
        );
    }

    public static function echoPayErrorProvider(): array
    {
        return [
            'decline 400' => [400, false],
            'decline 402' => [402, false],
            'not found 404' => [404, false],
            'no response / timeout' => [0, true],
            'server error 500' => [500, true],
            'bad gateway 502' => [502, true],
            'service unavailable 503' => [503, true],
        ];
    }
}
