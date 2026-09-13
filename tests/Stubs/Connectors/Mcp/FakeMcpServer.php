<?php

declare(strict_types=1);

namespace Tests\Stubs\Connectors\Mcp;

use NeuronAI\MCP\McpTransportInterface;
use NeuronAI\Testing\FakeMcpTransport;

/**
 * Builds the exact response sequence an MCP handshake consumes, so no test ever opens a socket.
 *
 * McpClient's construction alone burns two of these — `initialize` then `notifications/initialized`
 * (which sends but never receives) — before `tools/list` is the third request and the second response.
 * Getting that ordering wrong is why a fake queue "randomly" runs dry.
 */
final class FakeMcpServer
{
    /**
     * @param list<array<string, mixed>> $tools
     */
    public static function listing(array $tools): McpTransportInterface
    {
        return new FakeMcpTransport(
            self::initializeResponse(),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => $tools]],
        );
    }

    /**
     * A handshake plus N `tools/call` responses, for exercising the invoke path when the tools were
     * built from cached descriptors and `tools/list` therefore never runs.
     *
     * @param array<string, mixed> $callResult
     */
    public static function handshakeThenCalls(array $callResult, int $times = 1): McpTransportInterface
    {
        $responses = [self::initializeResponse()];

        for ($i = 0; $i < $times; $i++) {
            $responses[] = ['jsonrpc' => '2.0', 'id' => $i + 2, 'result' => $callResult];
        }

        return new FakeMcpTransport(...$responses);
    }

    /**
     * @return array<string, mixed>
     */
    private static function initializeResponse(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'serverInfo' => ['name' => 'fake-mcp', 'version' => '1.0.0'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function twoTools(): array
    {
        return [
            [
                'name' => 'searchJiraIssuesUsingJql',
                'description' => 'Search issues with JQL.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['jql' => ['type' => 'string', 'description' => 'A JQL string.']],
                    'required' => ['jql'],
                ],
            ],
            [
                'name' => 'createJiraIssue',
                'description' => 'Create an issue.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['summary' => ['type' => 'string', 'description' => 'Issue summary.']],
                    'required' => ['summary'],
                ],
            ],
        ];
    }
}
