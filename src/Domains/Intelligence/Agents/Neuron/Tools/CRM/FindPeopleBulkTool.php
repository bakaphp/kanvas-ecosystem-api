<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ExtractsPersonContacts;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\MatchesBulkNameTerms;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Find People Bulk', category: 'crm')]
class FindPeopleBulkTool extends Tool implements HasRunKey
{
    use ExtractsPersonContacts;
    use HasKanvasContext;
    use MatchesBulkNameTerms;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_people_bulk',
            description: 'Look up MANY people at once in the contacts directory by name. ALWAYS use this instead of '
                . 'calling find_person once per name whenever you have more than one name to check — cross-referencing '
                . 'a spreadsheet/CSV column, checking an attendee list, "which of these people do we already have". '
                . 'Pass every name in one call, separated by commas or new lines. Returns one row per name, in the '
                . 'order given, each with found=true/false and any matching person_id, email, phone and organization. '
                . 'For a single lookup, or to search by email/phone instead of name, use find_person.',
        );
    }

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
                description: 'All the names to look up, separated by commas or new lines — e.g. '
                    . '"Jorgelina Durán, Sandra Pichardo, Karina Piantini". Up to 100 per call.',
                required: true,
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
    public function __invoke(string $names, ?int $max_matches_per_name = null): array
    {
        $terms = $this->parseBulkTerms($names);

        if ($terms === []) {
            return [
                'searched' => 0,
                'matched' => 0,
                'not_found' => [],
                'results' => [],
                'error' => 'Provide at least one name, separated by commas or new lines.',
            ];
        }

        $results = $this->assembleBulkResults(
            $terms,
            $this->fetchCandidates($terms),
            $this->clampBulkMatchesPerTerm($max_matches_per_name),
            fn (People $person, int $score): array => [
                'person_id' => $person->getId(),
                'name' => $person->getName(),
                'email' => $this->primaryEmail($person),
                'phone' => $this->primaryPhone($person),
                'organization' => $person->organizations->first()?->name,
                'matched_tokens' => $score,
            ],
        );

        return [
            ...$results,
            'note' => 'One row per name, in the order given. found=false means that person is not in this company\'s '
                . 'directory — report them as blank/not found. Do NOT retry those names one at a time, the answer '
                . 'will be the same.',
        ];
    }

    /**
     * @param list<array{query: string, tokens: list<string>}> $terms
     * @return list<array{record: People, tokens: list<string>}>
     */
    private function fetchCandidates(array $terms): array
    {
        $tokens = $this->bulkLikeTokens($terms);

        $people = People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where(function (BuilderContract $query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';
                    $query->orWhere('name', 'like', $like)
                        ->orWhere('firstname', 'like', $like)
                        ->orWhere('lastname', 'like', $like);
                }
            })
            ->with([
                'contacts',
                'organizations' => fn ($q) => $q->select('organizations.id', 'name'),
            ])
            ->limit(self::BULK_MAX_CANDIDATE_ROWS)
            ->get(['id', 'firstname', 'middlename', 'lastname', 'name']);

        return $people->map(fn (People $person): array => [
            'record' => $person,
            'tokens' => $this->matchTokens($person->getName() . ' ' . (string) $person->name),
        ])->all();
    }
}
