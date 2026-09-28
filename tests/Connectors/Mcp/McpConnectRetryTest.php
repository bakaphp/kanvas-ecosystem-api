<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kanvas\Connectors\Mcp\Exceptions\McpFetchException;
use Kanvas\Connectors\Mcp\Transports\GuardedHttpMcpTransport;
use Tests\TestCase;

/**
 * A tool error kills the whole agent turn, so a vendor edge dropping one connection cost a run mid-flow
 * (Kernel, and it answered normally a second later). A connection that never opened is the one failure
 * safe to repeat — the request did not reach the server, so nothing ran twice.
 */
final class McpConnectRetryTest extends TestCase
{
    public function testAConnectionThatNeverOpenedIsRetried(): void
    {
        $transport = $this->transportFor([
            new ConnectException('Connection refused for URI https://mcp.example.test/mcp', new Request('POST', '/mcp')),
            new Response(200, [], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['ok' => true]])),
        ]);

        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $this->assertSame(['ok' => true], $transport->receive()['result']);
    }

    public function testItGivesUpAfterThreeAttemptsAndSaysSo(): void
    {
        $transport = $this->transportFor(array_fill(0, 3, new ConnectException(
            'Connection refused for URI https://mcp.example.test/mcp',
            new Request('POST', '/mcp')
        )));

        $this->expectException(McpFetchException::class);
        $this->expectExceptionMessage('after 3 attempts');

        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    }

    public function testAnAnsweredRequestIsNeverRepeated(): void
    {
        // A 500 may have run the tool — repeating a browser action or a payment is worse than failing.
        $transport = $this->transportFor([
            new Response(500, [], 'upstream exploded'),
            new Response(200, [], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])),
        ]);

        $this->expectException(McpFetchException::class);
        $this->expectExceptionMessage('HTTP 500');

        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    }

    /**
     * @param list<mixed> $queue
     */
    private function transportFor(array $queue): GuardedHttpMcpTransport
    {
        // http_errors off, like the real client: an error status is read by the transport, not thrown.
        $client = new Client([
            'handler' => HandlerStack::create(new MockHandler($queue)),
            'http_errors' => false,
        ]);

        return new class ('https://8.8.4.4/mcp', 0, 0, client: $client) extends GuardedHttpMcpTransport {
            public function __construct(
                ?string $url,
                int $agentsId,
                int $integrationsId,
                private readonly Client $client,
            ) {
                parent::__construct($url, $agentsId, $integrationsId);
            }

            protected function client(): Client
            {
                return $this->client;
            }
        };
    }
}
