<?php

declare(strict_types=1);

namespace Tests\Connectors\PayWay\Fakes;

use Kanvas\Connectors\PayWay\Contracts\PayWayClientInterface;
use Kanvas\Connectors\PayWay\DataTransferObject\PayWayResponse;
use Kanvas\Exceptions\ValidationException;
use LogicException;
use Tests\TestCase;

final class FakePayWayClientTest extends TestCase
{
    public function testInstantiatesWithoutNetworkAccess(): void
    {
        $fake = new FakePayWayClient();
        $this->assertInstanceOf(PayWayClientInterface::class, $fake);
    }

    public function testQueuedResponseIsReturnedAndCallIsRecorded(): void
    {
        $fake = new FakePayWayClient();
        $expected = PayWayResponse::fromArray([
            'success' => true,
            'returnCode' => '01',
            'message' => '3DS step required',
            'numeroPayWay' => '12345',
            'seguimientoDatos3ds' => ['paso' => '2'],
        ]);
        $fake->queueResponse('payUsing3ds', $expected);

        $result = $fake->payUsing3ds(['monto' => '5.00']);

        $this->assertSame($expected, $result);
        $this->assertSame([['path' => '', 'payload' => ['monto' => '5.00']]], $fake->getCalls('payUsing3ds'));
    }

    public function testThrowsWhenNoResponseQueued(): void
    {
        $fake = new FakePayWayClient();
        $this->expectException(LogicException::class);
        $fake->getTransaction('1877');
    }

    public function testMultipleQueuedResponsesConsumedInOrder(): void
    {
        $fake = new FakePayWayClient();
        $r1 = PayWayResponse::fromArray(['returnCode' => '01']);
        $r2 = PayWayResponse::fromArray(['returnCode' => '00']);
        $fake->queueResponse('completePayment', $r1);
        $fake->queueResponse('completePayment', $r2);

        $this->assertSame($r1, $fake->completePayment([]));
        $this->assertSame($r2, $fake->completePayment([]));
    }

    public function testThrowsQueuedException(): void
    {
        $fake = new FakePayWayClient();
        $boom = new ValidationException('declined');
        $fake->queueResponse('payUsing3ds', $boom);

        $this->expectException(ValidationException::class);
        $fake->payUsing3ds([]);
    }

    public function testVoidTransactionRecordsTraceIdInPayload(): void
    {
        $fake = new FakePayWayClient();
        $fake->queueResponse('voidTransaction', PayWayResponse::fromArray(['returnCode' => '00']));
        $fake->voidTransaction('1877', 'e873417e-3d8a-4d5f-b0a7-0e7ac5f5f553');

        $calls = $fake->getCalls('voidTransaction');
        $this->assertSame('1877', $calls[0]['path']);
        $this->assertSame('e873417e-3d8a-4d5f-b0a7-0e7ac5f5f553', $calls[0]['payload']['traceId']);
    }

    public function testGetCallsReturnsEmptyArrayForUnusedMethod(): void
    {
        $fake = new FakePayWayClient();
        $this->assertSame([], $fake->getCalls('traceTransaction'));
    }
}
