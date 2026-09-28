<?php

declare(strict_types=1);

namespace Tests\Intelligence;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kanvas\Intelligence\Agents\Services\LlmHttpRetryService;
use Tests\TestCase;

final class LlmHttpRetryServiceTest extends TestCase
{
    public function testRetriesOverloadedResponseAndSucceeds(): void
    {
        $mock = new MockHandler([
            new Response(503, [], '{"error":{"code":503,"status":"UNAVAILABLE"}}'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $response = $this->clientFor($mock)->post('https://provider.test/generateContent');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $mock->count(), 'Both queued responses should have been consumed.');
    }

    public function testGivesUpAfterMaxRetriesAndSurfacesTheProviderError(): void
    {
        $mock = new MockHandler(array_fill(
            0,
            LlmHttpRetryService::MAX_RETRIES + 2,
            new Response(503, [], '{"error":{"code":503}}')
        ));

        $client = $this->clientFor($mock);

        $this->expectException(ServerException::class);

        try {
            $client->post('https://provider.test/generateContent');
        } finally {
            // One initial attempt + MAX_RETRIES, leaving the spare queued response untouched.
            $this->assertSame(1, $mock->count());
        }
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $mock = new MockHandler([
            new Response(400, [], '{"error":{"code":400}}'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $client = $this->clientFor($mock);

        $this->expectException(ClientException::class);

        try {
            $client->post('https://provider.test/generateContent');
        } finally {
            $this->assertSame(1, $mock->count(), 'A 400 is deterministic and must not be replayed.');
        }
    }

    /**
     * Guzzle folds read timeouts into ConnectException (CURLE_OPERATION_TIMEOUTED lives in
     * CurlFactory's $connectionErrors), so retrying transport exceptions would replay a 220s
     * request the model may already be answering. This is the bound on worst-case runtime.
     */
    public function testDoesNotRetryTransportExceptions(): void
    {
        $mock = new MockHandler([
            new ConnectException(
                'cURL error 28: Operation timed out',
                new Request('POST', 'https://provider.test/generateContent')
            ),
            new Response(200, [], '{"ok":true}'),
        ]);

        $client = $this->clientFor($mock);

        $this->expectException(ConnectException::class);

        try {
            $client->post('https://provider.test/generateContent');
        } finally {
            $this->assertSame(1, $mock->count());
        }
    }

    public function testHonorsRetryAfterHeaderOnRateLimit(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '1'], '{"error":{"code":429}}'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $startedAt = microtime(true);
        $response = $this->clientFor($mock)->post('https://provider.test/generateContent');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(1.0, microtime(true) - $startedAt);
    }

    private function clientFor(MockHandler $mock): Client
    {
        return new Client(['handler' => LlmHttpRetryService::handlerStack($mock)]);
    }
}
