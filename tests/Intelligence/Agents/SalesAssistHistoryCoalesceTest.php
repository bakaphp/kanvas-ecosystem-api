<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Intelligence\Agents\Neuron\SalesAssistKanvasMessageHistory;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the duplicate-email feedback loop fix: the history coalescer merges
 * consecutive same-role turns but must NOT concatenate two identical copies of the
 * same turn (which the agent's dual-persistence produces). See
 * SalesAssistKanvasMessageHistory::coalesceContent.
 */
class SalesAssistHistoryCoalesceTest extends TestCase
{
    private function coalesce(string $existing, string $incoming): string
    {
        return (new ReflectionMethod(SalesAssistKanvasMessageHistory::class, 'coalesceContent'))
            ->invoke(null, $existing, $incoming);
    }

    public function testIdenticalCopiesCollapseToOne(): void
    {
        $reply = "Hi Max,\n\nOf course you can reschedule.\n\nBest,\n\nSally";

        $this->assertSame($reply, $this->coalesce($reply, $reply));
    }

    public function testContainedCopyIsDropped(): void
    {
        $longer = "Hi Max,\n\nHere are three slots.\n\nBest, Sally";
        $shorter = 'Hi Max,';

        $this->assertSame($longer, $this->coalesce($longer, $shorter));
        $this->assertSame($longer, $this->coalesce($shorter, $longer));
    }

    public function testGenuinelyDifferentTurnsStillConcatenate(): void
    {
        $a = 'Let me check the calendar.';
        $b = 'Friday at 3pm works.';

        $this->assertSame($a . "\n\n" . $b, $this->coalesce($a, $b));
    }

    public function testEmptyIncomingKeepsExisting(): void
    {
        $this->assertSame('Hi Max', $this->coalesce('Hi Max', ''));
        $this->assertSame('Hi Max', $this->coalesce('', 'Hi Max'));
    }
}
