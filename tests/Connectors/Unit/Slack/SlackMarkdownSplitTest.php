<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Slack;

use Kanvas\Connectors\Slack\Services\SlackMarkdownService;
use PHPUnit\Framework\TestCase;

final class SlackMarkdownSplitTest extends TestCase
{
    public function testShortReportKeepsStandardMarkdownVerbatim(): void
    {
        $markdown = "### Active plans\n\n* **Nuevo Diario — 50%**\n  * **Owner:** Max\n\n---\n\n[Brief](https://example.com/brief)\n`wordpress_site_url`";

        $this->assertSame([$markdown], SlackMarkdownService::split($markdown));
    }

    public function testKeepsListsLinksAndCodeBlocksTogetherWhenTheyFit(): void
    {
        $blocks = [
            str_repeat('Summary. ', 15) . "\n\n",
            "* **Plan A**\n  * Owner: Max\n  * [Brief](https://example.com/brief)\n\n",
            "```php\n// **not bold**\n\$value = 'hello';\n```\n",
        ];
        $markdown = implode('', $blocks);
        $chunks = SlackMarkdownService::split($markdown, 160);

        $this->assertSame($markdown, implode('', $chunks));
        $this->assertCount(2, $chunks);
        $this->assertStringContainsString(trim($blocks[1]), $chunks[1]);
        $this->assertStringContainsString($blocks[2], $chunks[1]);
    }

    public function testOversizedCodeBlocksAreClosedAndReopenedWithTheirLanguage(): void
    {
        $code = str_repeat("echo 'hola';\n", 40);
        $chunks = SlackMarkdownService::split("```php\n" . $code . '```', 160);
        $restored = '';

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(160, strlen($chunk));
            $this->assertStringStartsWith("```php\n", $chunk);
            $this->assertStringEndsWith("\n```", $chunk);
            $restored .= substr($chunk, 7, -4);
        }

        $this->assertSame($code, $restored);
    }

    public function testMultibyteLongLinesAndBlankLinesArePreserved(): void
    {
        $markdown = "### Estado\n\n" . str_repeat('Máximo 🙂 ', 100) . "\n\n\nDone.\n\n";
        $chunks = SlackMarkdownService::split($markdown, 160);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(160, strlen($chunk));
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
        }

        $this->assertSame($markdown, implode('', $chunks));
    }
}
