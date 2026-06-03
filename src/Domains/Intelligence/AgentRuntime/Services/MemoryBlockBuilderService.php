<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Services;

use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;
use Kanvas\Intelligence\AgentRuntime\Enums\MemoryFormatEnum;

// Pure transformation kept off the SSH-wired actions so dedup/cap logic stays
// unit-testable. Hermes (MEMORY.md) writes `§`-separated one-line facts;
// OpenClaw (KANVAS-LEARNINGS.md) writes each fact under its own `## N` header
// so the runtime's chunker can yield one chunk per fact. FIFO-capped at
// MAX_FACTS to bound prompt cost. Only `durable_facts` reach here —
// `briefing` and `proposed_actions` belong on AgentDailyCycle.
final class MemoryBlockBuilderService
{
    public const int MAX_FACTS = 80;

    private const string SEPARATOR = "\n§\n";

    private const string MARKDOWN_TITLE = '# Kanvas Durable Facts';

    public function __construct(
        private readonly MemoryFormatEnum $format = MemoryFormatEnum::Separator,
    ) {
    }

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
            'content' => $this->render($existing),
            'added' => $added,
            'evicted' => $evicted,
        ];
    }

    // Heuristic so callers (prompt builder, ledger emit) don't have to know
    // which runtime wrote the file. Hermes facts are one-liners — never
    // contain `##`; OpenClaw renders always start with a `## 1` header.
    /** @return list<string> */
    public static function parseFacts(string $memory): array
    {
        $trimmed = trim($memory);
        if ($trimmed === '') {
            return [];
        }

        if (preg_match('/^##\s/m', $trimmed)) {
            return self::parseMarkdown($trimmed);
        }

        return self::parseSeparator($trimmed);
    }

    /**
     * @param list<string> $facts
     */
    private function render(array $facts): string
    {
        return match ($this->format) {
            MemoryFormatEnum::Separator => implode(self::SEPARATOR, $facts),
            MemoryFormatEnum::MarkdownSections => self::renderMarkdown($facts),
        };
    }

    /**
     * @param list<string> $facts
     */
    private static function renderMarkdown(array $facts): string
    {
        $out = self::MARKDOWN_TITLE . "\n";
        foreach ($facts as $i => $fact) {
            $out .= "\n## " . ($i + 1) . "\n" . trim($fact) . "\n";
        }

        return $out;
    }

    /** @return list<string> */
    private static function parseSeparator(string $trimmed): array
    {
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

    /** @return list<string> */
    private static function parseMarkdown(string $trimmed): array
    {
        // Split at every `^## ` header line. The first chunk is whatever
        // precedes the first header (top-level title) — drop it. Each
        // remaining chunk is the body of one fact.
        $chunks = preg_split('/^##\s+.*$/m', $trimmed);
        if ($chunks === false || $chunks === []) {
            return [];
        }

        $out = [];
        $first = true;
        foreach ($chunks as $chunk) {
            if ($first) {
                $first = false;

                continue;
            }
            $body = trim($chunk);
            if ($body !== '') {
                $out[] = $body;
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
