<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Services;

use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;

/**
 * Pure transformation kept off the SSH-wired action so the dedup/cap logic
 * stays unit-testable. Hermes's MEMORY.md format is `§`-separated one-line
 * facts; we append `durable_facts` in that format, dedup against existing
 * entries, and FIFO-cap at MAX_FACTS to bound the agent's prompt cost.
 *
 * Only `durable_facts` reach the agent's memory bank — `briefing` and
 * `proposed_actions` belong on AgentDailyCycle (humans), not here.
 */
final class HermesMemoryBlockBuilderService
{
    public const int MAX_FACTS = 80;

    private const string SEPARATOR = "\n§\n";

    /**
     * @return array{content: string, added: int, evicted: int}
     */
    public function build(string $existingMemory, DailyLearningSummary $summary): array
    {
        $existing = $this->parse($existingMemory);
        $existingDedupKeys = array_map([$this, 'dedupKey'], $existing);

        $added = 0;
        foreach ($summary->durable_facts as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }

            $key = $this->dedupKey($candidate);
            if (in_array($key, $existingDedupKeys, true)) {
                continue;
            }

            $existing[] = $candidate;
            $existingDedupKeys[] = $key;
            $added++;
        }

        $evicted = 0;
        if (count($existing) > self::MAX_FACTS) {
            $evicted = count($existing) - self::MAX_FACTS;
            $existing = array_slice($existing, -self::MAX_FACTS);
        }

        return [
            'content' => implode(self::SEPARATOR, $existing),
            'added' => $added,
            'evicted' => $evicted,
        ];
    }

    /**
     * @return list<string>
     */
    private function parse(string $memory): array
    {
        $trimmed = trim($memory);
        if ($trimmed === '') {
            return [];
        }

        // Tolerant of leading/trailing whitespace around the § so we don't
        // shatter on hand-edited memory files.
        $parts = preg_split('/\n\s*§\s*\n/', $trimmed) ?: [$trimmed];

        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * Cheap heuristic — lowercased prefix of the first 50 chars catches the
     * common case where the LLM re-emits a fact with minor wording drift.
     * Swap for LLM-side dedup in v1.1 if near-duplicates leak through.
     */
    private function dedupKey(string $fact): string
    {
        return mb_strtolower(mb_substr(trim($fact), 0, 50));
    }
}
