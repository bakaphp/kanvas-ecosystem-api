<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Intelligence\Knowledge\Support\KnowledgeChunker;
use Tests\TestCase;

class KnowledgeChunkerTest extends TestCase
{
    public function testShortContentProducesOneChunk(): void
    {
        $chunks = new KnowledgeChunker()->chunk('Refunds are processed within 30 days.');

        $this->assertCount(1, $chunks);
        $this->assertSame('Refunds are processed within 30 days.', $chunks[0]);
    }

    public function testWhitespaceOnlyContentProducesNoChunks(): void
    {
        $this->assertSame([], new KnowledgeChunker()->chunk("   \n\n  \t "));
    }

    public function testParagraphsThatCannotCoexistUnderLimitSplit(): void
    {
        $paragraph = str_repeat('a', 800);
        $chunks = new KnowledgeChunker()->chunk("{$paragraph}\n\n{$paragraph}");

        // Two 800-char paragraphs cannot co-fit under the 1000-char cap.
        $this->assertCount(2, $chunks);
    }

    public function testSmallParagraphsPackTogether(): void
    {
        $chunks = new KnowledgeChunker()->chunk("First paragraph.\n\nSecond paragraph.\n\nThird paragraph.");

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('First paragraph.', $chunks[0]);
        $this->assertStringContainsString('Third paragraph.', $chunks[0]);
    }

    public function testOversizeSingleParagraphIsKeptWhole(): void
    {
        $huge = str_repeat('b', 1500);
        $chunks = new KnowledgeChunker()->chunk($huge);

        $this->assertCount(1, $chunks);
        $this->assertSame(1500, strlen($chunks[0]));
    }
}
