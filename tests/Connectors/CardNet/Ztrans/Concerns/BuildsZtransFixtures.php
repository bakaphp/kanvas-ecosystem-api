<?php

declare(strict_types=1);

namespace Tests\Connectors\CardNet\Ztrans\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\CardNet\Ztrans\Enums\ConfigurationEnum;
use Kanvas\Connectors\CardNet\Ztrans\Services\RedirectReceiverService;
use Kanvas\Connectors\CardNet\Ztrans\ThreeDSServerClient;
use Kanvas\Connectors\CardNet\Ztrans\ZtransClient;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\CardNetZtrans\CardNetZtransProcessor;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;

trait BuildsZtransFixtures
{
    protected const string PAN = '5555555555555557';
    protected const string CVV = '123';

    protected function fakeApp(array $overrides = []): Apps
    {
        $settings = [
            ConfigurationEnum::MERCHANT_ID->value => '100000001',
            ConfigurationEnum::TERMINAL_ID->value => '00000001',
            ConfigurationEnum::THREE_DS_INTEGRATOR_CODE->value => '100000001',
            ConfigurationEnum::THREE_DS_API_KEY->value => 'test-api-key',
            ConfigurationEnum::REFUND_BEARER_TOKEN->value => 'bearer-token',
            ...$overrides,
        ];

        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('get')->andReturnUsing(fn (string $key): mixed => $settings[$key] ?? null);

        return $app;
    }

    protected function buildProcessor(Apps $app): CardNetZtransProcessor
    {
        return new CardNetZtransProcessor(
            app: $app,
            ztrans: new ZtransClient($app),
            threeDSServer: new ThreeDSServerClient($app),
            redirectReceiver: $this->fakeRedirectReceiver(),
        );
    }

    protected function fakeRedirectReceiver(): RedirectReceiverService
    {
        $receiver = Mockery::mock(RedirectReceiverService::class);
        $receiver->shouldReceive('redirectUrl')
            ->andReturnUsing(fn (Order $order, Payments $payment): string => 'https://api.test/v1/receiver/abc?payment_id=' . $payment->id);

        return $receiver;
    }

    protected function card(): array
    {
        return [
            'number' => self::PAN,
            'cvv' => self::CVV,
            'expiration_date' => '12/27',
            'firstname' => 'Test',
            'lastname' => 'User',
        ];
    }

    protected function authenticationFrictionless(): array
    {
        return ['threeDSServerTransID' => 'tds-1', 'transStatus' => 'Y'];
    }

    protected function authenticationChallenge(): array
    {
        return [
            'browserChallengeUrl' => 'https://lab.cardnet.com.do/servicios/3ds/server/authentication/load',
            'browserChallengeToken' => 'tds-1',
            'transStatus' => 'C',
            'threeDSServerTransID' => 'tds-1',
        ];
    }

    protected function statusAuthenticated(): array
    {
        return [
            'messageVersion' => '2.1.0',
            'dsTransID' => 'ds-1',
            'transStatus' => 'Y',
            'authenticationValue' => 'aav-value',
            'eci' => '02',
        ];
    }

    protected function approved(string $referenceKey = 'pnRef', string $reference = 'txn-100'): array
    {
        return [
            'response-code' => '00',
            'internal-response-code' => '0000',
            'response-code-desc' => 'Transaction Approved',
            'response-code-source' => 'gw',
            'approval-code' => '010123',
            $referenceKey => $reference,
        ];
    }

    protected function declined(string $responseCode, string $internalCode = '1016'): array
    {
        return [
            'response-code' => $responseCode,
            'internal-response-code' => $internalCode,
            'response-code-desc' => 'Declined',
            'response-code-source' => 'gw',
        ];
    }

    protected function idempotencyKeySequence(): ResponseSequence
    {
        return Http::sequence()
            ->push('ikey:key-1')
            ->push('ikey:key-2')
            ->push('ikey:key-3');
    }

    protected function sentTo(string $suffix): array
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), $suffix))
            ->map(fn (array $pair): Request => $pair[0])
            ->values()
            ->all();
    }

    protected function buildOrder(string $currency = 'DOP'): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->exists = true;
        $order->id = 1;
        $order->order_number = 12345;
        $order->ip_address = '10.0.0.1';
        $order->user_email = 'test@example.com';
        $order->currency = $currency;

        $order->shouldReceive('getTotalDueAmount')->andReturn(100.00)->byDefault();
        $order->shouldReceive('getTotalTaxAmount')->andReturn(18.00)->byDefault();

        return $order;
    }

    protected function buildPayment(string $status = 'pending'): Payments
    {
        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->exists = true;
        $payment->id = 1;
        $payment->idempotency_key = 'aaaabbbb-cccc-4000-8000-ddddeeeeffff';
        $payment->status = $status;
        $payment->amount = 100.00;
        $payment->metadata = [];

        $payment->shouldReceive('save')->andReturnSelf()->byDefault();
        $payment->shouldReceive('addLog')->andReturnNull()->byDefault();
        $payment->shouldReceive('addMetadata')
            ->andReturnUsing(function (array $data) use ($payment): void {
                $existing = $payment->metadata ?? [];
                $payment->metadata = [
                    ...$existing,
                    ...$data,
                    'data' => [...($existing['data'] ?? []), ...($data['data'] ?? [])],
                ];
            })
            ->byDefault();
        $payment->shouldReceive('getMetadata')
            ->andReturnUsing(fn (string $key): mixed => $payment->metadata['data'][$key] ?? null)
            ->byDefault();
        $payment->shouldReceive('markAsPaid')
            ->andReturnUsing(function (array $metadata = []) use ($payment): void {
                $payment->status = PaymentStatusEnum::PAID->value;
                $payment->addMetadata($metadata);
            })
            ->byDefault();

        return $payment;
    }

    protected function assertNoCardDataStored(Payments $payment): void
    {
        $stored = json_encode($payment->metadata);

        $this->assertStringNotContainsString(self::PAN, $stored);
        $this->assertStringNotContainsString('"cvv"', $stored);
        $this->assertStringNotContainsString('"' . self::CVV . '"', $stored);
    }
}
