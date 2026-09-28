<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Search\Contracts\NameSearchInterface;
use Baka\Search\NameSearchResolver;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kanvas\Guild\Search\MatchesBulkNameTerms;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Bulk name lookup for the pipeline records that hang off a contact — leads and deals are the same
 * query and the same matching, differing only in model and output shape.
 *
 * Company-wide read: an internal-teammate capability, never the customer-facing prospect surface
 * (see Agents/CLAUDE.md audience rule).
 */
abstract class FindCrmRecordsBulkTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use MatchesBulkNameTerms;
    use TrackByInputs;

    /** Singular record name, used in the operator-facing messages. */
    abstract protected function noun(): string;

    /** Tenant-scoped query with the relations present() needs already eager-loaded. */
    abstract protected function baseQuery(): Builder;

    /**
     * @return array<string, mixed>
     */
    abstract protected function present(Model $record, int $score): array;

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'names',
                type: PropertyType::STRING,
                description: sprintf(
                    'All the contact names or %s titles to look up, separated by commas or new lines. Up to 100 per call.',
                    $this->noun(),
                ),
                required: true,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: sprintf('Which %ss to include: "all" (default), "open", or "closed".', $this->noun()),
                required: false,
            ),
            new ToolProperty(
                name: 'max_matches_per_name',
                type: PropertyType::INTEGER,
                description: 'How many candidate matches to return per name. Defaults to 3, max 10.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $names, ?string $status = null, ?int $max_matches_per_name = null): array
    {
        $terms = $this->parseBulkTerms($names);

        if ($terms === []) {
            return [
                'searched' => 0,
                'matched' => 0,
                'not_found' => [],
                'results' => [],
                'error' => sprintf(
                    'Provide at least one contact name or %s title, separated by commas or new lines.',
                    $this->noun(),
                ),
            ];
        }

        $results = $this->assembleBulkResults(
            $terms,
            $this->fetchCandidates($terms, strtolower(trim($status ?? 'all'))),
            $this->clampBulkMatchesPerTerm($max_matches_per_name),
            fn (Model $record, int $score): array => $this->present($record, $score),
        );

        return [
            ...$results,
            'note' => sprintf(
                'One row per name, in the order given. found=false means there is no %1$s for that name in this '
                . 'company — report it as blank/not found. Do NOT retry those names one at a time, the answer '
                . 'will be the same.',
                $this->noun(),
            ),
        ];
    }

    protected function ownerName(Model $record): ?string
    {
        $owner = $record->owner;

        return $owner ? trim($owner->firstname . ' ' . $owner->lastname) : null;
    }

    protected function isOpen(Model $record): bool
    {
        return $record->status === null || $record->status < 2;
    }

    /** Overridable so the engine branch — and the tenant re-scope in hydrate() — can be driven in tests. */
    protected function nameSearch(Model $model): ?NameSearchInterface
    {
        return new NameSearchResolver()->for($this->app, $model);
    }

    /**
     * @param list<array{query: string, tokens: list<string>}> $terms
     * @return list<array{record: Model, tokens: list<string>}>
     */
    private function fetchCandidates(array $terms, string $status): array
    {
        $model = $this->baseQuery()->getModel();
        $search = $this->nameSearch($model);

        $records = $search === null
            ? $this->scanForCandidates($terms, $status)
            : $this->hydrate($search->idsFor(
                $model,
                $this->app,
                $this->company,
                $model->searchQueryBy(),
                $terms,
                NameSearchInterface::DEFAULT_CANDIDATES_PER_TERM,
            ), $status);

        return $records->map(fn (Model $record): array => [
            'record' => $record,
            'tokens' => $this->matchTokens((string) $record->title . ' ' . (string) $record->people?->getName()),
        ])->all();
    }

    /**
     * Status stays out of the index: "open" is `status IS NULL OR status < 2`, which every engine
     * expresses differently and none expresses well. The engine answers "which records match these
     * names"; this narrows that to the ones the caller asked for, and re-applies the tenant scopes
     * from baseQuery() so a stale document can never surface another company's record.
     *
     * @param list<string> $ids
     *
     * @return Collection<int, Model>
     */
    private function hydrate(array $ids, string $status): Collection
    {
        if ($ids === []) {
            return new Collection();
        }

        $query = $this->baseQuery();

        return $this->applyStatus($query, $status)
            ->whereIn($query->getModel()->getTable() . '.id', array_map('intval', $ids))
            ->get();
    }

    /**
     * @param list<array{query: string, tokens: list<string>}> $terms
     *
     * @return Collection<int, Model>
     */
    private function scanForCandidates(array $terms, string $status): Collection
    {
        $query = $this->baseQuery();
        $model = $query->getModel();
        $relation = $model->people();
        $table = $model->getTable();
        $peopleTable = $relation->getRelated()->getTable();

        // Scoring reads the title and the contact's name as one string, so the prefilter has to count
        // tokens across both — a lead matching one token in each would be lost if they were filtered
        // separately. Joining beats a per-token EXISTS: belongsTo can't multiply rows.
        $columns = [
            $table . '.title',
            $peopleTable . '.name',
            $peopleTable . '.firstname',
            $peopleTable . '.lastname',
        ];

        return $this->applyStatus($query, $status)
            ->leftJoin($peopleTable, $peopleTable . '.id', '=', $table . '.' . $relation->getForeignKeyName())
            ->select($table . '.*')
            ->where(function (BuilderContract $inner) use ($terms, $columns): void {
                foreach ($terms as $term) {
                    $this->applyBulkCandidateFilter($inner, $term['tokens'], $columns);
                }
            })
            ->limit(static::BULK_MAX_CANDIDATE_ROWS)
            ->get();
    }

    private function applyStatus(Builder $query, string $status): Builder
    {
        return $query
            ->when($status === 'open', fn ($q) => $q->where(
                fn ($s) => $s->whereNull('status')->orWhere('status', '<', 2),
            ))
            ->when($status === 'closed', fn ($q) => $q->where('status', '>=', 2));
    }
}
