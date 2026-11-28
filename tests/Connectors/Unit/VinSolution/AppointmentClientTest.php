<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\VinSolution;

use Carbon\Carbon;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentClient;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentData;
use Kanvas\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AppointmentClientTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, response: mixed}> */
    private array $recorded = [];

    public function testCreateSalesAppointmentReturnsThePlainTextId(): void
    {
        $client = $this->clientReturning(new Response(201, [], '900200300'));

        $id = $client->createSalesAppointment('token', $this->sampleAppointment());

        $this->assertSame('900200300', $id);

        $request = $this->recorded[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('vin.api.crm.appointment/Sales', (string) $request->getUri());
        $this->assertSame('Bearer token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/vnd.coxauto.v2+json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('1', json_decode((string) $request->getBody(), true)['purpose']);
    }

    public function testCreateSalesAppointmentFailsWhenNoIdComesBack(): void
    {
        $client = $this->clientReturning(new Response(201, [], '  '));

        $this->expectException(ValidationException::class);
        $client->createSalesAppointment('token', $this->sampleAppointment());
    }

    public function testListAppointmentsSendsTheRequiredContractAndReturnsItems(): void
    {
        $client = $this->clientReturning(new Response(200, [], json_encode([
            'href' => '...',
            'limit' => '(1,50)',
            'count' => 1,
            'items' => [['id' => 900200300, 'status' => 'ACTIVE']],
        ])));

        $items = $client->listAppointments(
            'token',
            50010,
            60020,
            800200300
        );

        $this->assertCount(1, $items);
        $this->assertSame(900200300, $items[0]['id']);

        parse_str($this->recorded[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame('50010', $query['dealerId']);
        $this->assertSame('60020', $query['UserId']);
        $this->assertSame('800200300', $query['CustomerId']);
        $this->assertSame('(1,50)', $query['limit']);
    }

    public function testListAppointmentsReturnsEmptyWhenTheBodyHasNoItems(): void
    {
        $client = $this->clientReturning(new Response(200, [], json_encode(['count' => 0])));

        $items = $client->listAppointments(
            'token',
            1,
            2,
            3
        );

        $this->assertSame([], $items);
    }

    private function sampleAppointment(): AppointmentData
    {
        return new AppointmentData(
            startsAt: Carbon::parse('2026-12-01T16:00:00Z'),
            endsAt: Carbon::parse('2026-12-01T16:30:00Z'),
            dealerId: '50010',
            leadId: '700200300',
            customerId: '800200300',
            assignedUserId: '60020',
        );
    }

    private function clientReturning(Response $response): AppointmentClient
    {
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->recorded));

        return new AppointmentClient(new GuzzleClient(['handler' => $stack]));
    }
}
