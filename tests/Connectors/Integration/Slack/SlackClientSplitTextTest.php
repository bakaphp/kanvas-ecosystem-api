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
            $this->assertLessThanOrEqual(13, strlen($chunk));
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
        // The limit is BYTES: each 🙂 is 4 bytes, so a 10-byte budget holds 2 whole emoji (8 bytes),
        // never a partial one. 30 emoji → 15 chunks, each valid UTF-8 and within the byte budget.
        $chunks = Client::splitText(str_repeat('🙂', 30), 10);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(10, strlen($chunk));
            $this->assertSame($chunk, mb_convert_encoding($chunk, 'UTF-8', 'UTF-8'), 'chunk must be valid UTF-8');
        }
        $this->assertSame(str_repeat('🙂', 30), implode('', $chunks));
    }

    /**
     * The real regression (Sentry KANVAS-ECOSYSTEM-61V): a long agent reply full of emoji + accented
     * names measured under the CHARACTER limit but over Slack's BYTE limit, so chat.update returned
     * msg_too_long. Every chunk must fit the byte budget and the reply must round-trip intact.
     */
    public function testEmojiAndAccentHeavyReplyChunksStayWithinByteBudget(): void
    {
        $line = '*   *Máximo L. Castro Pou* (`max@mctekk.com`) — *CTO - Gerente de Tecnología* 🏆';
        $text = "*🏆 5+ Years of Service*\n" . implode("\n", array_fill(0, 60, $line));
        $this->assertGreaterThan(3000, strlen($text), 'fixture must exceed the byte budget to exercise splitting');

        $chunks = Client::splitText($text, 3000);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(3000, strlen($chunk));
        }
        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame($text, implode("\n", $chunks));
    }
}
