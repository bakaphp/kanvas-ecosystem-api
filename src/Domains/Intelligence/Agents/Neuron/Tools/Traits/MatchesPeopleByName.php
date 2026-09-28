<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Contracts\PeopleCandidateSourceInterface;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Search\PeopleCandidateSourceResolver;
use Kanvas\Guild\Search\MatchesBulkNameTerms;

/**
 * The one name-matching pass behind both find_people_bulk and the people_match export. They have to
 * share it: an agent that answers "Jorgelina is in the directory" in chat and then hands over a CSV
 * saying she isn't is worse than either surface alone.
 */
trait MatchesPeopleByName
{
    use ExtractsPersonContacts;
    use MatchesBulkNameTerms;

    /**
     * $maxTerms raises the ceiling past one query's worth; the list is always resolved in batches of
     * BULK_MAX_TERMS because BULK_MAX_CANDIDATE_ROWS is a per-QUERY budget — one prefilter over too
     * many names fills it with whatever a common surname pulled in, and a name that does match comes
     * back "not found". Dedup happens once over the whole list, before chunking.
     *
     * @return array{searched: int, matched: int, not_found: list<string>, results: list<array<string, mixed>>}
     */
    protected function matchPeopleByName(
        Apps $app,
        Companies $company,
        string $names,
        ?int $maxMatchesPerName = null,
        ?int $maxTerms = null,
    ): array {
        $maxMatches = $this->clampBulkMatchesPerTerm($maxMatchesPerName);
        $merged = [
            'searched' => 0,
            'matched' => 0,
            'not_found' => [],
            'results' => [],
        ];

        $batches = array_chunk($this->parseBulkTerms($names, $maxTerms), static::BULK_MAX_TERMS);

        foreach ($batches as $terms) {
            $batch = $this->assembleBulkResults(
                $terms,
                $this->fetchPeopleCandidates($app, $company, $terms),
                $maxMatches,
                fn (People $person, int $score): array => [
                    'person_id' => $person->getId(),
                    'name' => $person->getName(),
                    'email' => $this->primaryEmail($person),
                    'phone' => $this->primaryPhone($person),
                    'organization' => $person->organizations->first()?->name,
                    'matched_tokens' => $score,
                ],
            );

            $merged['searched'] += $batch['searched'];
            $merged['matched'] += $batch['matched'];
            $merged['not_found'] = [...$merged['not_found'], ...$batch['not_found']];
            $merged['results'] = [...$merged['results'], ...$batch['results']];
        }

        return $merged;
    }

    /**
     * @param list<array{query: string, tokens: list<string>}> $terms
     *
     * @return list<array{record: People, tokens: list<string>}>
     */
    private function fetchPeopleCandidates(Apps $app, Companies $company, array $terms): array
    {
        return $this->candidateSource($app)
            ->candidatesFor($app, $company, $terms)
            ->map(fn (People $person): array => [
                'record' => $person,
                'tokens' => $this->matchTokens($person->getName() . ' ' . (string) $person->name),
            ])
            ->all();
    }

    protected function candidateSource(Apps $app): PeopleCandidateSourceInterface
    {
        return new PeopleCandidateSourceResolver()->for($app);
    }
}
