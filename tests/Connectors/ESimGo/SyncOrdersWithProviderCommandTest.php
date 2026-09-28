<?php

declare(strict_types=1);

namespace Tests\Connectors\ESimGo;

use App\Console\Commands\Connectors\ESim\SyncOrdersWithProviderCommand;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\ESimGo\Services\ESimService;
use Kanvas\Souk\Orders\Models\Order;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class SyncOrdersWithProviderCommandTest extends TestCase
{
    /**
     * Regression for Sentry KANVAS-ECOSYSTEM-21V: eSIM Go returns a transient 500
     * ("unexpected error occurred") for a valid ICCID. That must be logged and skipped —
     * it must NOT be reported to Sentry, must NOT increment cancel_counter, and must NOT
     * cancel the order (previously 3 provider hiccups would cancel a valid paid order).
     */
    public function testEsimGoTransientServerErrorIsLoggedAndDoesNotCancelOrder(): void
    {
        $serverError = new ServerException(
            'Server error: 500 Internal Server Error',
            new Request('GET', '/v2.4/esims/8932042000008470321/bundles/esims_1GB_7D_AR_V2'),
            new Response(500, [], '{"message":"unexpected error occurred"}'),
        );

        $eSimService = Mockery::mock(ESimService::class);
        $eSimService->shouldReceive('getAppliedBundleStatus')
            ->once()
            ->andThrow($serverError);

        // A transient provider error must never touch the order's cancellation state.
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 123;
        $order->shouldNotReceive('set');
        $order->shouldNotReceive('cancel');
        $order->shouldNotReceive('fulfillCancelled');
        $order->shouldNotReceive('fulfill');
        $order->shouldNotReceive('completed');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'eSIM Go transient provider error')
                    && $context['order_id'] === 123
                    && $context['iccid'] === '8932042000008470321';
            });

        $command = new SyncOrdersWithProviderCommand();
        $method = new ReflectionMethod($command, 'esimGoFulfillment');
        $method->setAccessible(true);

        // Must not re-throw — the sync loop has to survive one bad eSIM and keep going.
        $method->invoke($command, $eSimService, $order, '8932042000008470321', 'esims_1GB_7D_AR_V2');

        $this->assertTrue(true);
    }
}
