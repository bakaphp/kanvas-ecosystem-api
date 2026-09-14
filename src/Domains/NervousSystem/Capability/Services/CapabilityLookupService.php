<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\DataTransferObject\ConnectorReadiness;
use Kanvas\NervousSystem\Capability\Models\Tool;

/**
 * Answers "is there a Kanvas tool for this?" against the catalog `SyncAgentToolsCommand` populates.
 *
 * An LLM can only see the tools it holds; absence is not represented anywhere in its context. So it
 * resolves a request it has no tool for toward the closest-named tool it does have — which is how
 * "create a new Google Sheet" reaches `create_google_sheet_tab`. This makes absence a query result
 * instead of a blind spot, and separates the four answers that need four different next moves: you
 * have it, the platform has it but you were not granted it, we own it and this tenant has not
 * configured it, nobody has built it.
 *
 * The third and fourth are the pair worth separating: "we have no Odoo integration" and "we have one
 * and nobody filled in the API key" look identical from inside a toolset, and only one of them is a
 * roadmap item.
 */
class CapabilityLookupService
{
    private const int MAX_RESULTS = 8;

    /** Below this a term matches almost everything ("ai", "id", "a"), which is worse than no match. */
    private const int MIN_TERM_LENGTH = 3;

    /** Prefix length used to match across word endings. Long enough to stay specific. */
    private const int STEM_LENGTH = 5;

    /**
     * Tools guaranteed a slot per matched term. Two rather than one because related tools cluster —
     * `schedule_reminder` and `schedule_agent_task` both match "recurring", and only the second can
     * actually do work on a schedule.
     */
    private const int COVERAGE_PER_TERM = 2;

    /**
     * Words that appear in so many tool descriptions that they carry no signal. Kept deliberately
     * short: over-filtering loses real terms ("new", "get") that do discriminate in this catalog.
     */
    private const array STOP_WORDS = [
        'and',
        'are',
        'can',
        'for',
        'from',
        'has',
        'have',
        'how',
        'into',
        'need',
        'the',
        'that',
        'this',
        'want',
        'what',
        'with',
        'you',
        'your',
    ];

    private readonly ConnectorReadinessService $readiness;

    public function __construct(
        private readonly Agent $agent,
        ?ConnectorReadinessService $readiness = null,
    ) {
        $this->readiness = $readiness ?? new ConnectorReadinessService();
    }

    /**
     * @return array{
     *     status: string,
     *     topic: string,
     *     granted: array<int, array<string, mixed>>,
     *     available: array<int, array<string, mixed>>,
     *     needs_configuration: array<int, array<string, mixed>>,
     *     not_found: bool,
     *     note: string
     * }
     */
    public function lookup(string $topic, ?string $category = null): array
    {
        $topic = trim($topic);
        $terms = $this->terms($topic);

        if ($terms === []) {
            return $this->emptyResult(
                $topic,
                'Give me something to search for — a few words describing the capability, e.g. '
                    . '"create a spreadsheet" or "refund an order".',
            );
        }

        $matches = $this->scoredMatches($terms, $category);

        if ($matches->isEmpty()) {
            return $this->emptyResult(
                $topic,
                "No Kanvas tool matches \"{$topic}\". Do NOT substitute a tool whose name merely sounds "
                    . 'similar — that is how the wrong record gets changed. Before calling it impossible, run '
                    . 'list_active_integrations: this search covers TOOLS, and the capability may live in a '
                    . 'connected service instead. Then tell the user what you searched for.',
            );
        }

        $grantedIds = new CapabilityProvider()
            ->getActiveTools($this->agent)
            ->map(fn (Tool $tool): int => (int) $tool->getKey())
            ->all();

        $granted = $matches->filter(fn (Tool $tool): bool => in_array((int) $tool->getKey(), $grantedIds, true));
        $available = $matches->reject(fn (Tool $tool): bool => in_array((int) $tool->getKey(), $grantedIds, true));

        $unconfigured = $this->unconfiguredConnectors($matches);

        return [
            'status' => 'success',
            'topic' => $topic,
            'granted' => $granted->map($this->presentGranted(...))->values()->all(),
            'available' => $available->map($this->presentAvailable(...))->values()->all(),
            'needs_configuration' => array_map(
                fn (ConnectorReadiness $readiness): array => $readiness->toArray(),
                $unconfigured,
            ),
            'not_found' => false,
            'note' => $this->note($granted, $available, $unconfigured),
        ];
    }

    /**
     * Connectors behind the matched tools that this tenant has not set up, deduped by slug — five
     * Google Sheets tools share one missing service-account key and should say so once.
     *
     * @param Collection<int, Tool> $matches
     * @return list<ConnectorReadiness>
     */
    private function unconfiguredConnectors(Collection $matches): array
    {
        $bySlug = [];

        foreach ($matches as $tool) {
            $readiness = $this->readiness->forHandler($tool->handler, $this->agent->app);

            if ($readiness !== null && ! $readiness->ready) {
                $bySlug[$readiness->slug] = $readiness;
            }
        }

        return array_values($bySlug);
    }

    /**
     * @param list<string> $terms
     * @return Collection<int, Tool>
     */
    private function scoredMatches(array $terms, ?string $category): Collection
    {
        $query = Tool::query()
            ->active()
            ->fromAppOrGlobal($this->agent->app)
            ->where(function (Builder $inner) use ($terms): void {
                foreach ($terms as $term) {
                    // Filter on the stem, not the whole term — the scorer can only rank rows the
                    // query returned, so a narrower WHERE here silently caps what stemming can find.
                    $needle = $this->stem($term) ?? $term;

                    $inner->orWhere('name', 'LIKE', "%{$needle}%")
                        ->orWhere('description', 'LIKE', "%{$needle}%");
                }
            });

        if ($category !== null && trim($category) !== '') {
            $query->inCategory(trim($category));
        }

        $scored = $query
            ->with(['category', 'agentTypes'])
            ->get()
            ->sortByDesc(fn (Tool $tool): int => $this->score($tool, $terms));

        return $this->withTermCoverage($scored, $terms);
    }

    /**
     * Take the top matches, then make sure every term that matched something is represented by at
     * least its own best tool.
     *
     * Ranking alone answers the wrong question when a request names two capabilities. "Send recurring
     * emails every Monday" scores every send_* tool around 10 on "send" and "email", while the tool
     * that actually does the recurring part — `schedule_agent_task` — scores 1, because its
     * description says "recurrence_cron" and never says "recurring". The top slice was all sending and
     * no scheduling, so the model concluded the platform could not schedule and filed a capability gap
     * for a tool it was already holding. Coverage is what stops one loud concept hiding a quiet one.
     *
     * @param Collection<int, Tool> $scored Already ordered best-first.
     * @param list<string> $terms
     * @return Collection<int, Tool>
     */
    private function withTermCoverage(Collection $scored, array $terms): Collection
    {
        $selected = $scored->take(self::MAX_RESULTS);

        foreach ($terms as $term) {
            $forTerm = $scored
                ->filter(fn (Tool $tool): bool => $this->matchesTerm($tool, $term))
                ->take(self::COVERAGE_PER_TERM);

            foreach ($forTerm as $tool) {
                $alreadyIn = $selected->contains(
                    fn (Tool $selectedTool): bool => $selectedTool->getKey() === $tool->getKey(),
                );

                if (! $alreadyIn) {
                    $selected = $selected->push($tool);
                }
            }
        }

        return $selected->take(self::MAX_RESULTS + (count($terms) * self::COVERAGE_PER_TERM))->values();
    }

    private function matchesTerm(Tool $tool, string $term): bool
    {
        $haystack = strtolower($tool->name . ' ' . (string) $tool->description);
        $stem = $this->stem($term);

        return str_contains($haystack, $term)
            || ($stem !== null && str_contains($haystack, $stem));
    }

    /**
     * A name hit outranks a description hit: a description mentions neighbouring concepts, a name is
     * what the tool is. Without the weighting every tool whose description says "lead" ties with
     * `create_lead`.
     *
     * Stems score lower than whole words but they are what make the search usable. People describe a
     * capability in different words than the tool does — asking for "recurring emails" has to reach
     * `schedule_agent_task`, whose description says "repeating" and "recurrence_cron" and contains the
     * word "recurring" nowhere. Literal matching missed it, the model concluded the platform could not
     * schedule anything, and filed a capability gap for a tool it was already holding.
     *
     * @param list<string> $terms
     */
    private function score(Tool $tool, array $terms): int
    {
        $name = strtolower($tool->name);
        $description = strtolower((string) $tool->description);

        $score = 0;

        foreach ($terms as $term) {
            $stem = $this->stem($term);

            $score += match (true) {
                str_contains($name, $term) => 6,
                $stem !== null && str_contains($name, $stem) => 4,
                default => 0,
            };

            $score += match (true) {
                str_contains($description, $term) => 2,
                $stem !== null && str_contains($description, $stem) => 1,
                default => 0,
            };
        }

        return $score;
    }

    /**
     * A crude prefix stem — enough to bridge the endings that actually cost us matches:
     * recurring/recurrence, emails/email, scheduling/schedule, invoices/invoice.
     *
     * Not a real stemmer on purpose. A proper one is a dependency and a tuning problem, where five
     * characters of prefix fixes the whole observed failure class; the lower score keeps a loose match
     * from outranking a real one.
     */
    private function stem(string $term): ?string
    {
        return strlen($term) > self::STEM_LENGTH ? substr($term, 0, self::STEM_LENGTH) : null;
    }

    /**
     * @return list<string>
     */
    private function terms(string $topic): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($topic), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false) {
            return [];
        }

        $terms = array_filter(
            $words,
            fn (string $word): bool => strlen($word) >= self::MIN_TERM_LENGTH
                && ! in_array($word, self::STOP_WORDS, true),
        );

        return array_values(array_unique($terms));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGranted(Tool $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'category' => $tool->category?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAvailable(Tool $tool): array
    {
        return [
            ...$this->presentGranted($tool),
            'held_by_agent_types' => $tool->agentTypes
                ->pluck('name')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param Collection<int, Tool> $granted
     * @param Collection<int, Tool> $available
     * @param list<ConnectorReadiness> $unconfigured
     */
    private function note(Collection $granted, Collection $available, array $unconfigured): string
    {
        $parts = [];

        if ($granted->isNotEmpty()) {
            $parts[] = 'You already have: ' . $granted->pluck('name')->implode(', ') . '. Use these.';
        }

        if ($available->isNotEmpty()) {
            $holders = $available
                ->flatMap(fn (Tool $tool): array => $tool->agentTypes->pluck('name')->all())
                ->filter()
                ->unique()
                ->values();

            $parts[] = 'The platform also has ' . $available->pluck('name')->implode(', ')
                . ' which you were not granted'
                . ($holders->isNotEmpty() ? ' — held by: ' . $holders->implode(', ') : '')
                . '. Offer to hand the work to an agent that has it, or ask an admin to grant it. Do not '
                . 'improvise with a different tool. (This lists registry grants only: if one of these is '
                . 'already in your own tool list, you have it.)';
        }

        if ($unconfigured !== []) {
            foreach ($unconfigured as $readiness) {
                $parts[] = $readiness->label . ' is NOT set up for this company, so its tools will fail even '
                    . 'where you hold them. This is a setup gap, not a missing feature — tell the user exactly '
                    . 'this: ' . implode(' ', $readiness->issues);
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{
     *     status: string,
     *     topic: string,
     *     granted: array<int, array<string, mixed>>,
     *     available: array<int, array<string, mixed>>,
     *     needs_configuration: array<int, array<string, mixed>>,
     *     not_found: bool,
     *     note: string
     * }
     */
    private function emptyResult(string $topic, string $note): array
    {
        return [
            'status' => 'success',
            'topic' => $topic,
            'granted' => [],
            'available' => [],
            'needs_configuration' => [],
            'not_found' => true,
            'note' => $note,
        ];
    }
}
