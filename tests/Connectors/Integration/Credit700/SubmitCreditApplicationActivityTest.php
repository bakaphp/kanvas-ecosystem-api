<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Credit700;

use Kanvas\Connectors\Credit700\Workflow\SubmitCreditApplicationActivity;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class SubmitCreditApplicationActivityTest extends TestCase
{
    #[DataProvider('rejectionResponseProvider')]
    public function testRejectionReasonSummarizesTheGatewayError(array $response, string $expected): void
    {
        $activity = new ReflectionClass(SubmitCreditApplicationActivity::class)->newInstanceWithoutConstructor();
        $reason = new ReflectionMethod(SubmitCreditApplicationActivity::class, 'rejectionReason')
            ->invoke($activity, $response);

        $this->assertSame($expected, $reason);
    }

    public static function rejectionResponseProvider(): array
    {
        return [
            'gateway error, the shape RouteOne actually returns' => [
                ['Creditsystem_Error' => ['@attributes' => ['id' => '101'], 'message' => 'Invalid account']],
                '{"id":"101"} Invalid account',
            ],
            'gateway error as a bare string' => [
                ['Creditsystem_Error' => 'Invalid account'],
                'Invalid account',
            ],
            'no error, RouteOne just withheld the transaction id' => [
                ['XML_Report' => ['Token' => '700DSO-04f4c5c0']],
                'no error returned, RouteOne gave back no transaction id',
            ],
        ];
    }

    public function testRejectionReasonNeverLeaksTheApplicantPayload(): void
    {
        $activity = new ReflectionClass(SubmitCreditApplicationActivity::class)->newInstanceWithoutConstructor();
        $reason = new ReflectionMethod(SubmitCreditApplicationActivity::class, 'rejectionReason')
            ->invoke($activity, [
                'Creditsystem_Error' => 'Invalid account',
                'XML_Report' => [
                    'SSN' => '123-45-6789',
                    'DRIVERSLICENSENO' => 'D1234567',
                ],
            ]);

        $this->assertStringNotContainsString('123-45-6789', $reason);
        $this->assertStringNotContainsString('D1234567', $reason);
    }
}
