<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Slack;

use Kanvas\Connectors\Slack\Client;
use Tests\TestCaseUnit;

final class SlackClientSplitTextTest extends TestCaseUnit
{
    public function testShortTextStaysSingleChunk(): void
    {
        $this->assertSame(['hello world'], Client::splitText('hello world', 100));
    }

    public function testEmptyTextStaysSingleChunk(): void
    {
        $this->assertSame([''], Client::splitText('', 100));
    }

    public function testSplitsOnNewlineBoundariesWithoutExceedingLimit(): void
    {
        $text = implode("\n", ['line-a', 'line-b', 'line-c', 'line-d']);

        $chunks = Client::splitText($text, 13); // fits ~2 lines per chunk

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(13, mb_strlen($chunk));
        }
        // Reassembling on newlines must reproduce the original — nothing dropped or duplicated.
        $this->assertSame($text, implode("\n", $chunks));
        $this->assertGreaterThan(1, count($chunks));
    }

    public function testHardSplitsASingleOverlongLine(): void
    {
        $chunks = Client::splitText(str_repeat('a', 50), 20);

        $this->assertSame(['aaaaaaaaaaaaaaaaaaaa', 'aaaaaaaaaaaaaaaaaaaa', 'aaaaaaaaaa'], $chunks);
        $this->assertSame(str_repeat('a', 50), implode('', $chunks));
    }

    public function testMultibyteCharactersAreNotSplitMidCharacter(): void
    {
        // 30 emoji, each 1 "character" to mb_strlen. Hard-split at 10 → 3 clean chunks.
        $chunks = Client::splitText(str_repeat('🙂', 30), 10);

        $this->assertCount(3, $chunks);
        foreach ($chunks as $chunk) {
            $this->assertSame(10, mb_strlen($chunk));
        }
    }
}
