<?php

declare(strict_types=1);

namespace Tests\Intelligence\AgentRuntime\Services;

use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;
use Kanvas\Intelligence\AgentRuntime\Services\MemoryBlockBuilderService;
use Tests\TestCase;

/**
 * Pure-function tests — no DB, no SSH. Verifies dedup-and-cap semantics
 * shared across Hermes (MEMORY.md) and OpenClaw (KANVAS-LEARNINGS.md) memory
 * files — both use the §-separated format this builder produces.
 */
class MemoryBlockBuilderServiceTest extends TestCase
{
    private function summary(array $durableFacts): DailyLearningSummary
    {
        return new DailyLearningSummary(
            briefing: 'irrelevant for this builder',
            proposed_actions: [],
            durable_facts: $durableFacts,
            skills_emerged: [],
            self_improvement_score: 0.1,
        );
    }

    public function testAppendsToEmptyMemory(): void
    {
        $result = new MemoryBlockBuilderService()->build(
            existingMemory: '',
            summary: $this->summary(['Steven is the PNP contact.', 'EVT starts 1 workday after sample.']),
        );

        $this->assertSame(2, $result['added']);
        $this->assertSame(0, $result['evicted']);
        $this->assertStringContainsString('Steven is the PNP contact.', $result['content']);
        $this->assertStringContainsString('EVT starts 1 workday after sample.', $result['content']);
        // §-separator present between the two facts
        $this->assertStringContainsString("\n§\n", $result['content']);
    }

    public function testDedupsAgainstExistingFacts(): void
    {
        $existing = implode(
            "\n§\n",
            [
                'Steven is the PNP contact.',
                'EVT starts 1 workday after sample receipt.',
            ],
        );

        $result = new MemoryBlockBuilderService()->build(
            existingMemory: $existing,
            summary: $this->summary([
                'Steven is the PNP contact.',           // exact match
                'steven is the pnp contact.',           // case differs only — same prefix key
                'New fact about something else.',       // truly new
            ]),
        );

        $this->assertSame(1, $result['added']);
        $this->assertSame(0, $result['evicted']);
        $this->assertStringContainsString('New fact about something else.', $result['content']);
        $this->assertSame(
            1,
            substr_count($result['content'], 'Steven is the PNP contact.'),
            'Existing fact must not be duplicated when re-emitted',
        );
    }

    public function testFifoEvictsOldestWhenOverMaxFacts(): void
    {
        $cap = MemoryBlockBuilderService::MAX_FACTS;

        // Build $cap unique existing facts (oldest first), then push 5 new ones.
        $existingFacts = [];
        for ($i = 1; $i <= $cap; $i++) {
            $existingFacts[] = sprintf('Old fact %03d about routine matters.', $i);
        }
        $existing = implode("\n§\n", $existingFacts);

        $result = new MemoryBlockBuilderService()->build(
            existingMemory: $existing,
            summary: $this->summary([
                'New fact A.',
                'New fact B.',
                'New fact C.',
                'New fact D.',
                'New fact E.',
            ]),
        );

        $this->assertSame(5, $result['added']);
        $this->assertSame(5, $result['evicted']);
        // Oldest 5 must be gone
        $this->assertStringNotContainsString('Old fact 001', $result['content']);
        $this->assertStringNotContainsString('Old fact 005', $result['content']);
        // The cap+1th old fact survives
        $this->assertStringContainsString('Old fact 006', $result['content']);
        // All new facts present
        foreach (['A', 'B', 'C', 'D', 'E'] as $tag) {
            $this->assertStringContainsString("New fact {$tag}.", $result['content']);
        }
    }

    public function testIgnoresBlankCandidateFacts(): void
    {
        $result = new MemoryBlockBuilderService()->build(
            existingMemory: '',
            summary: $this->summary(['', '   ', "\n\n"]),
        );

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, $result['evicted']);
        $this->assertSame('', $result['content']);
    }
}
