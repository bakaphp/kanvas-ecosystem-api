<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Services;

use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;

/**
 * Resolves a person's GENUINE current employers from their stored employment history
 * (peoples_employment_history status=1) and answers "is this company their current
 * employer?". Shared by the enrichment cleanup and the job-change backfill so both
 * agree on what counts as a real current employer — Apollo returns the full history,
 * so a job left years ago (e.g. Baninter, ended 2001) must never be treated as the
 * current employer or surfaced as a move.
 *
 * Names are matched normalized (legal suffixes stripped, lowercased) so "Baninter"
 * matches a stored "BANINTER SRL" — the same normalizer the org create-path uses.
 */
class PersonCurrentEmployerService
{
    /** @var array<int, string[]> memoized normalized current-employer names per person */
    private array $cache = [];

    /**
     * Is `$company` one of the person's current (status=1) employers? When we have no
     * employment history on file we can't disprove it, so we answer true rather than
     * risk discarding a genuine move.
     */
    public function isGenuineCurrentEmployer(int $peopleId, string $company): bool
    {
        $current = $this->currentEmployerNames($peopleId);

        if ($current === []) {
            return true;
        }

        return in_array($this->normalize($company), $current, true);
    }

    /**
     * Normalized names of the person's current (status=1) employers, memoized per person.
     *
     * @return string[]
     */
    public function currentEmployerNames(int $peopleId): array
    {
        if (array_key_exists($peopleId, $this->cache)) {
            return $this->cache[$peopleId];
        }

        $orgIds = PeopleEmploymentHistory::query()
            ->where('peoples_id', $peopleId)
            ->where('status', 1)
            ->where('is_deleted', 0)
            ->pluck('organizations_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $names = $orgIds === []
            ? []
            : Organization::query()->whereIn('id', $orgIds)->pluck('name')->all();

        $normalized = array_values(array_unique(array_filter(array_map(
            fn ($name) => $this->normalize((string) $name),
            $names,
        ))));

        return $this->cache[$peopleId] = $normalized;
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(trim(OrganizationNameNormalizerService::normalize($name)));
    }
}
