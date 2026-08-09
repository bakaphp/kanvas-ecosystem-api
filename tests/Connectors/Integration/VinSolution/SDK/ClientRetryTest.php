<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\VinSolution\SDK;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\VinSolution\Client;
use Tests\TestCase;

final class ClientRetryTest extends TestCase
{
    /**
     * A single connection reset (cURL 35) should be retried transparently and
     * the eventual 200 returned — the exact failure behind KANVAS-ECOSYSTEM-4NE / 5R2.
     */
    public function testRetriesTransientConnectionResetThenSucceeds(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'cURL error 35: Recv failure: Connection reset by peer',
                new Request('GET', '/leads/id/1')
            ),
            new Response(200, [], '{"ok":true}'),
        ]);

        $client = new GuzzleClient(['handler' => Client::buildHandlerStack($mock)]);

        $response = $client->get('/leads/id/1');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $mock->count(), 'Both the failure and the retry should be consumed.');
    }

    public function testRetriesTransientServerErrorThenSucceeds(): void
    {
        $mock = new MockHandler([
            new Response(503, [], 'Service Unavailable'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $client = new GuzzleClient(['handler' => Client::buildHandlerStack($mock)]);

        $response = $client->get('/leadSources/id/1');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $mock->count());
    }

    /**
     * Retries are bounded: a persistently failing endpoint gives up after the
     * cap (1 original + 3 retries) and surfaces the ConnectException.
     */
    public function testGivesUpAfterMaxRetries(): void
    {
        $mock = new MockHandler([
            new ConnectException('reset', new Request('GET', '/leads/id/1')),
            new ConnectException('reset', new Request('GET', '/leads/id/1')),
            new ConnectException('reset', new Request('GET', '/leads/id/1')),
            new ConnectException('reset', new Request('GET', '/leads/id/1')),
        ]);

        $client = new GuzzleClient(['handler' => Client::buildHandlerStack($mock)]);

        $this->expectException(ConnectException::class);

        $client->get('/leads/id/1');
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $mock = new MockHandler([
            new Response(404, [], 'Not Found'),
        ]);

        $client = new GuzzleClient([
            'handler' => Client::buildHandlerStack($mock),
            'http_errors' => false,
        ]);

        $response = $client->get('/vehicles/trade?leadId=1');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $mock->count(), 'A 404 must be returned as-is, not retried.');
    }
}
