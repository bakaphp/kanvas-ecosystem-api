<?php

declare(strict_types=1);

namespace Tests\Intelligence\AgentRuntime\Services;

use Kanvas\Intelligence\AgentRuntime\Services\CliJsonExtractorService;
use Tests\TestCase;

class CliJsonExtractorServiceTest extends TestCase
{
    public function testExtractsSimpleObject(): void
    {
        $this->assertSame(
            '{"ok":true}',
            CliJsonExtractorService::extractFirstObject('{"ok":true}'),
        );
    }

    public function testExtractsPrettyPrintedMultilineObject(): void
    {
        $input = "{\n  \"ok\": true,\n  \"latency\": 37\n}";
        $this->assertSame($input, CliJsonExtractorService::extractFirstObject($input));
    }

    public function testIgnoresPreambleNoise(): void
    {
        $input = "Config warnings:\n- plugins.entries.web-search: plugin not found\n[plugins] plugins.allow is empty\n{\"ok\":true}";
        $this->assertSame(
            '{"ok":true}',
            CliJsonExtractorService::extractFirstObject($input),
        );
    }

    public function testIgnoresTrailingNoise(): void
    {
        $input = "{\"ok\":true}\nopenclaw >\nMore warnings emitted late";
        $this->assertSame(
            '{"ok":true}',
            CliJsonExtractorService::extractFirstObject($input),
        );
    }

    public function testIgnoresBothPreambleAndTrailingNoise(): void
    {
        $input = "[plugins] noise\n{\"ok\":true,\"x\":1}\n[plugins] more noise";
        $this->assertSame(
            '{"ok":true,"x":1}',
            CliJsonExtractorService::extractFirstObject($input),
        );
    }

    public function testHandlesNestedObjects(): void
    {
        $input = '{"outer":{"inner":{"deep":1}},"x":2}';
        $this->assertSame($input, CliJsonExtractorService::extractFirstObject($input));
    }

    public function testBracesInsideStringsDoNotAffectDepth(): void
    {
        // The `}` inside the string value must NOT close the outer object.
        $input = '{"text":"hello } world","ok":true}';
        $this->assertSame($input, CliJsonExtractorService::extractFirstObject($input));
    }

    public function testOpenBraceInsideStringsDoNotAffectDepth(): void
    {
        $input = '{"text":"unclosed { brace","ok":true}';
        $this->assertSame($input, CliJsonExtractorService::extractFirstObject($input));
    }

    public function testEscapedQuotesInsideStrings(): void
    {
        // Inner \" must NOT terminate the string.
        $input = '{"text":"he said \"hi\" then","ok":true}';
        $this->assertSame($input, CliJsonExtractorService::extractFirstObject($input));
    }

    public function testEscapedBackslashFollowedByQuoteTerminatesString(): void
    {
        // `\\` is an escaped backslash → next `"` IS a real string terminator.
        $input = '{"path":"C:\\\\temp","ok":true}';
        $this->assertSame($input, CliJsonExtractorService::extractFirstObject($input));
    }

    public function testReturnsNullOnEmptyInput(): void
    {
        $this->assertNull(CliJsonExtractorService::extractFirstObject(''));
    }

    public function testReturnsNullWhenNoOpenBrace(): void
    {
        $this->assertNull(
            CliJsonExtractorService::extractFirstObject('just plain text with no braces'),
        );
    }

    public function testReturnsNullForUnbalancedObject(): void
    {
        // Opens but never closes.
        $this->assertNull(
            CliJsonExtractorService::extractFirstObject('{"ok":true'),
        );
    }

    public function testReturnsOnlyFirstObjectWhenMultipleAtTopLevel(): void
    {
        $input = '{"first":true}{"second":false}';
        $this->assertSame(
            '{"first":true}',
            CliJsonExtractorService::extractFirstObject($input),
        );
    }

    /**
     * Realistic OpenClaw `health --json` output — the actual reason this
     * extractor exists.
     */
    public function testHandlesRealOpenClawHealthOutput(): void
    {
        $input = <<<'OUT'
Config warnings:\n- plugins.entries.web-search: plugin not found: web-search (stale config entry ignored; remove it from plugins config)
[plugins] plugins.allow is empty; discovered non-bundled plugins may auto-load: lossless-claw
Config warnings:\n- plugins.entries.web-search: plugin not found
{
  "ok": true,
  "ts": 1779881057211,
  "durationMs": 37,
  "channels": {
    "slack": {
      "configured": true,
      "probe": {
        "ok": true,
        "status": 200
      }
    }
  }
}
OUT;
        $extracted = CliJsonExtractorService::extractFirstObject($input);
        $this->assertNotNull($extracted);

        $decoded = json_decode($extracted, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertSame(37, $decoded['durationMs']);
        $this->assertTrue($decoded['channels']['slack']['probe']['ok']);
    }
}
