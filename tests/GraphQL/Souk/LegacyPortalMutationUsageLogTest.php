<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyPortalMutationUsageLogTest extends TestCase
{
    public static function legacyMutations(): array
    {
        return [
            'initiatePayerAuthentication' => ['initiatePayerAuthentication', 'orderId', 'order_id'],
            'completeDeviceData' => ['completeDeviceData', 'orderId', 'order_id'],
            'validatePayerAuthResult' => ['validatePayerAuthResult', 'orderId', 'order_id'],
            'reversePayment' => ['reversePayment', 'paymentId', 'payment_id'],
        ];
    }

    #[DataProvider('legacyMutations')]
    public function testLegacyPortalMutationLogsItsUsage(string $mutation, string $argument, string $logKey): void
    {
        Log::spy();
        $appId = app(Apps::class)->getId();
        $userId = auth()->id();
        $this->assertNotNull($userId);

        $this->graphQL("
            mutation {
                {$mutation}({$argument}: 987654321) {
                    status
                }
            }
        ");

        Log::shouldHaveReceived('warning')
            ->with('Deprecated Portal mutation called', Mockery::on(
                fn (array $context): bool => $context['mutation'] === $mutation
                    && (string) $context[$logKey] === '987654321'
                    && $context['apps_id'] === $appId
                    && $context['users_id'] === $userId
            ))
            ->once();
    }
}
