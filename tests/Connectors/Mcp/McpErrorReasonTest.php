<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Connectors\Mcp\Support\McpErrorReason;
use PHPUnit\Framework\TestCase;

final class McpErrorReasonTest extends TestCase
{
    public function testGooglesErrorMessageIsSurfaced(): void
    {
        $body = json_encode([
            'error' => [
                'code' => 403,
                'message' => 'Google Drive MCP API has not been used in project 307987033853 before or it is disabled.',
                'status' => 'PERMISSION_DENIED',
            ],
        ]);

        $this->assertSame(
            'Google Drive MCP API has not been used in project 307987033853 before or it is disabled.',
            McpErrorReason::fromBody((string) $body)
        );
    }

    public function testOAuthStyleErrorsAreSurfaced(): void
    {
        $this->assertSame('Token expired', McpErrorReason::fromBody('{"error":"invalid_token","error_description":"Token expired"}'));
        $this->assertSame('invalid_token', McpErrorReason::fromBody('{"error":"invalid_token"}'));
    }

    public function testAnHtmlErrorPageIsReducedToItsTextAndCapped(): void
    {
        $reason = McpErrorReason::fromBody('<html><body><h1>Forbidden</h1>' . str_repeat('<p>detail</p>', 200) . '</body></html>');

        $this->assertStringStartsWith('Forbidden', $reason);
        $this->assertLessThanOrEqual(300, mb_strlen($reason));
    }

    public function testAScopeChallengeWinsOverTheBody(): void
    {
        $challenge = 'Bearer error="insufficient_scope", scope="https://www.googleapis.com/auth/drive"';

        $this->assertSame($challenge, McpErrorReason::fromBody('{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}', $challenge));
    }

    public function testAJsonRpcResultIsNotEchoedAsTheReason(): void
    {
        $this->assertSame('', McpErrorReason::fromBody('{"jsonrpc":"2.0","id":2,"result":{"tools":[{"name":"copy_file"}]}}'));
    }

    public function testAJsonBodyCutOffByTheReadCapIsNotEchoed(): void
    {
        $this->assertSame('', McpErrorReason::fromBody('{"id":2,"jsonrpc":"2.0","result":{"tools":[{"annotations":{"title":"Copy file"'));
        $this->assertSame('', McpErrorReason::fromBody("event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":2,\"result\":"));
    }

    public function testAnyChallengeIsSurfaced(): void
    {
        $challenge = 'Bearer realm="https://accounts.google.com/", scope="https://www.googleapis.com/auth/drive"';

        $this->assertSame($challenge, McpErrorReason::fromBody('', $challenge));
    }

    public function testAnEmptyBodyGivesAnEmptyReason(): void
    {
        $this->assertSame('', McpErrorReason::fromBody(''));
    }
}
