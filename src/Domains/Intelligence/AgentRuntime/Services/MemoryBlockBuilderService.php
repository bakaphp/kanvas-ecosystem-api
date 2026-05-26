<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Services;

use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;

// Pure transformation kept off the SSH-wired actions so dedup/cap logic stays
// unit-testable. Hermes (MEMORY.md) and OpenClaw (KANVAS-LEARNINGS.md) both
// write `§`-separated one-line facts; FIFO-capped at MAX_FACTS to bound prompt
// cost. Only `durable_facts` reach here — `briefing` and `proposed_actions`
// belong on AgentDailyCycle.
final class MemoryBlockBuilderService
{
    public const int MAX_FACTS = 80;

    private const string SEPARATOR = "\n§\n";

    /**
     * @return array{content: string, added: int, evicted: int}
     */
    public function build(string $existingMemory, DailyLearningSummary $summary): array
    {
        $existing = self::parseFacts($existingMemory);
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

    // One source of truth for the `§` format — also called by the prompt
    // builder to feed prior facts back to the LLM for upstream dedup.
    /** @return list<string> */
    public static function parseFacts(string $memory): array
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

    // Lowercased 50-char prefix — catches re-emissions with minor wording
    // drift. Swap for LLM-side dedup if near-duplicates leak through.
    private function dedupKey(string $fact): string
    {
        return mb_strtolower(mb_substr(trim($fact), 0, 50));
    }
}
