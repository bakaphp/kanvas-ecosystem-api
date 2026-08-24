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

    /**
     * The classification helper only helps if every EchoPay catch actually calls it. Guard the
     * source so a new catch block can't silently reintroduce the unconditional report() that
     * flooded Sentry with routine declines (KANVAS-ECOSYSTEM-43N).
     */
    public function testEveryEchoPayCatchGatesTheReport(): void
    {
        $source = file_get_contents(new ReflectionClass(PortalPaymentProcessor::class)->getFileName());

        preg_match_all(
            '/catch \(EchoPayException \$e\) \{\s*+(?<body>[^\n]*+)/',
            $source,
            $matches
        );

        $this->assertNotEmpty($matches['body'], 'No EchoPayException catch blocks found — did the class move?');

        foreach ($matches['body'] as $firstStatement) {
            $this->assertSame(
                'if ($this->isEchoPayGatewayFailure($e)) {',
                trim($firstStatement),
                'An EchoPayException catch reports without classifying the failure first.'
            );
        }
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
