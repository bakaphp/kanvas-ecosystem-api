<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Baka\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MemoryCategoryEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\CodingRepositoryMemory;

/**
 * Turns a finished session's handoff into individual memories about the repository.
 *
 * The handoff is a record of one run and ages out; a memory is a claim about the codebase that should
 * outlive it. Splitting them is what lets the fiftieth session still benefit from the first without
 * carrying forty-nine transcripts.
 *
 * Nothing here calls a model. The handoff is already structured JSON — asking an LLM to re-read its own
 * structured output would cost a turn to learn nothing.
 */
class ExtractSessionMemoriesAction
{
    private const int MAX_PER_SESSION = 12;
    private const int MIN_LENGTH = 12;
    private const string DUPLICATE_ENTRY = '1062';

    public function __construct(
        private readonly AgentTaskSession $session,
    ) {
    }

    /**
     * @return list<CodingRepositoryMemory>
     */
    public function execute(): array
    {
        $repoSlug = Str::trimToNull($this->session->repo_slug);
        $handoff = Str::trimToNull((string) $this->session->handoff);

        // Without a repository there is nothing for a memory to be *about* — a lesson from an anonymous
        // scratch directory has nowhere useful to apply.
        if ($repoSlug === null || $handoff === null) {
            return [];
        }

        $claims = $this->dedupe($this->claimsFrom($handoff));
        $stored = [];

        foreach (array_slice($claims, 0, self::MAX_PER_SESSION) as $claim) {
            $memory = $this->remember($repoSlug, $claim['category'], $claim['content']);

            if ($memory !== null) {
                $stored[] = $memory;
            }
        }

        return $stored;
    }

    /**
     * A handoff that lists the same sentence under two headings would otherwise spend the budget twice
     * and report two memories where one was stored.
     *
     * @param list<array{category: MemoryCategoryEnum, content: string}> $claims
     * @return list<array{category: MemoryCategoryEnum, content: string}>
     */
    private function dedupe(array $claims): array
    {
        $seen = [];
        $unique = [];

        foreach ($claims as $claim) {
            $hash = CodingRepositoryMemory::hashFor($claim['content']);

            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $unique[] = $claim;
        }

        return $unique;
    }

    /**
     * @return list<array{category: MemoryCategoryEnum, content: string}>
     */
    private function claimsFrom(string $handoff): array
    {
        $decoded = $this->decode($handoff);

        if ($decoded === null) {
            return [];
        }

        $claims = [];

        foreach ($this->strings($decoded['gotchas'] ?? null) as $text) {
            $claims[] = ['category' => MemoryCategoryEnum::GOTCHA, 'content' => $text];
        }

        foreach ($this->strings($decoded['architecture'] ?? null) as $text) {
            $claims[] = ['category' => MemoryCategoryEnum::ARCHITECTURE, 'content' => $text];
        }

        foreach ($this->strings($decoded['conventions'] ?? null) as $text) {
            $claims[] = ['category' => MemoryCategoryEnum::CONVENTION, 'content' => $text];
        }

        // Decisions carry their reasoning, and the reasoning is the part worth keeping: "we did X"
        // without "because Y" reads as an instruction to the next agent rather than as context.
        foreach ($this->decisions($decoded['decisions'] ?? null) as $text) {
            $claims[] = ['category' => MemoryCategoryEnum::DECISION, 'content' => $text];
        }

        return $claims;
    }

    /**
     * The handoff is asked for as bare JSON, but models wrap it in prose or a fenced block often enough
     * that refusing those would throw away most of what we asked for.
     *
     * Candidates are tried in order of how likely they are to be the real payload. A fenced block wins,
     * then each balanced `{...}` span. A greedy "first brace to last brace" match looks like it would
     * work and does not: prose mentioning `{placeholder}` before the JSON swallows both into one
     * unparseable string, and the extraction silently yields nothing.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $handoff): ?array
    {
        foreach ($this->jsonCandidates($handoff) as $candidate) {
            $decoded = json_decode(trim($candidate), true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function jsonCandidates(string $handoff): array
    {
        $candidates = [$handoff];

        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $handoff, $fenced) === 1) {
            $candidates[] = $fenced[1];
        }

        return [...$candidates, ...$this->balancedObjects($handoff)];
    }

    /**
     * Every balanced `{...}` span, longest first — the handoff object contains nested objects, so the
     * outermost balanced span is the one wanted. Quoted strings are skipped so a brace inside a value
     * cannot end the scan early.
     *
     * Counted in BYTES, because `$text[$i]` and `substr()` are byte operations. `mb_strlen` here made
     * the loop stop short of the end of any string holding a multi-byte character — so a handoff
     * written in Spanish lost its memories and reported none, rather than failing.
     *
     * @return list<string>
     */
    private function balancedObjects(string $text): array
    {
        $spans = [];
        $length = strlen($text);

        for ($start = 0; $start < $length; $start++) {
            if ($text[$start] !== '{') {
                continue;
            }

            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($i = $start; $i < $length; $i++) {
                $char = $text[$i];

                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = ! $inString;

                    continue;
                }

                if ($inString) {
                    continue;
                }

                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $spans[] = substr($text, $start, $i - $start + 1);

                        break;
                    }
                }
            }
        }

        usort($spans, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $spans;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $texts = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $text = Str::trimToNull($entry);

            if ($text !== null && mb_strlen($text) >= self::MIN_LENGTH) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * @return list<string>
     */
    private function decisions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $texts = [];

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $texts = [...$texts, ...$this->strings($entry)];

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $what = Str::trimToNull((string) ($entry['what'] ?? ''));
            $why = Str::trimToNull((string) ($entry['why'] ?? ''));

            if ($what === null) {
                continue;
            }

            $texts[] = $why === null ? $what : $what . ' — ' . $why;
        }

        return $texts;
    }

    /**
     * The same lesson is reported on nearly every run, so a repeat bumps a counter instead of adding a
     * row. How often something is independently rediscovered is also the best confidence signal
     * available without asking anyone to grade it.
     */
    private function remember(string $repoSlug, MemoryCategoryEnum $category, string $content): ?CodingRepositoryMemory
    {
        $hash = CodingRepositoryMemory::hashFor($content);

        /** @var CodingRepositoryMemory|null $existing */
        $existing = CodingRepositoryMemory::query()
            ->fromApp($this->session->app)
            ->fromCompany($this->session->company)
            ->forRepo($repoSlug)
            ->where('content_hash', $hash)
            ->first();

        if ($existing !== null) {
            $existing->times_reported++;
            $existing->last_reported_at = Carbon::now();
            $existing->saveOrFail();

            return $existing;
        }

        try {
            return $this->insert($repoSlug, $category, $content, $hash);
        } catch (QueryException $e) {
            // Another session on the same repository stored the identical lesson between the lookup and
            // the insert. The unique index is what makes that safe; losing the race is not an error.
            if (! str_contains($e->getMessage(), self::DUPLICATE_ENTRY)) {
                throw $e;
            }

            return $this->bumpExisting($repoSlug, $hash);
        }
    }

    private function insert(
        string $repoSlug,
        MemoryCategoryEnum $category,
        string $content,
        string $hash
    ): CodingRepositoryMemory {
        $memory = new CodingRepositoryMemory();
        $memory->apps_id = $this->session->apps_id;
        $memory->companies_id = $this->session->companies_id;
        $memory->repo_slug = $repoSlug;
        $memory->agent_id = $this->session->agent_id;
        $memory->source_session_id = $this->session->getId();
        $memory->category = $category->value;
        $memory->content = $content;
        $memory->content_hash = $hash;
        $memory->status = CodingRepositoryMemory::STATUS_ACTIVE;
        $memory->times_reported = 1;
        $memory->last_reported_at = Carbon::now();
        $memory->saveOrFail();

        return $memory;
    }

    private function bumpExisting(string $repoSlug, string $hash): ?CodingRepositoryMemory
    {
        /** @var CodingRepositoryMemory|null $existing */
        $existing = CodingRepositoryMemory::query()
            ->fromApp($this->session->app)
            ->fromCompany($this->session->company)
            ->forRepo($repoSlug)
            ->where('content_hash', $hash)
            ->first();

        if ($existing === null) {
            return null;
        }

        $existing->times_reported++;
        $existing->last_reported_at = Carbon::now();
        $existing->saveOrFail();

        return $existing;
    }
}
