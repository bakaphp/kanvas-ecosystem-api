<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Concerns;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ExtractsPersonContacts;

/**
 * Shared by find_people_duplicates and the people_duplicates export, so the chat report and the CSV
 * recommend the same thing for the same group. The recommendation is computed here, never left to
 * the model.
 */
trait PresentsPeopleDuplicateGroups
{
    use ExtractsPersonContacts;

    protected const array DUPLICATE_REASONS = ['external_id_conflict', 'exact_name', 'lastname_match', 'firstname_match', 'email_match'];
    protected const string SAFE_TO_MERGE = 'safe_to_merge';
    protected const string REVIEW_FIRST = 'review_first';

    /**
     * @param list<DuplicateReviewGroup> $groups
     * @return list<array<string, mixed>>
     */
    protected function presentDuplicateGroups(Apps $app, Companies $company, array $groups): array
    {
        $people = $this->loadDuplicateMembers($app, $company, $groups);
        $presented = [];

        foreach ($groups as $group) {
            $members = collect($group->member_ids)
                ->map(fn ($id): ?People => $people->get((int) $id))
                ->filter()
                ->values();

            if ($members->count() < 2) {
                continue;
            }

            $keep = $members->first(fn (People $person): bool => $person->getId() === (int) $group->canonical_id) ?? $members->first();

            $presented[] = [
                'group_id' => $group->getId(),
                'reason' => $group->reason,
                'canonical_id' => $keep->getId(),
                'shared_value' => $this->sharedValue($group->reason, $keep, $members),
                'recommendation' => $this->recommendation($group->reason, $keep, $members),
                'members' => $members
                    ->sortBy(fn (People $person): int => $person->is($keep) ? 0 : $person->getId())
                    ->map(fn (People $person): array => [
                        'person_id' => $person->getId(),
                        'role' => $person->is($keep) ? 'keep' : 'merge',
                        'name' => $person->getName(),
                        'emails' => $this->allEmails($person),
                        'phones' => $this->allPhones($person),
                        'organizations' => $this->organizationNames($person),
                        'created_at' => $person->created_at?->toDateTimeString(),
                        'differs_from_keep' => $person->is($keep) ? [] : $this->differences($keep, $person),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $presented;
    }

    /**
     * @param list<DuplicateReviewGroup> $groups
     * @return Collection<int, People>
     */
    private function loadDuplicateMembers(Apps $app, Companies $company, array $groups): Collection
    {
        $ids = array_unique(array_merge(...array_map(fn (DuplicateReviewGroup $group): array => $group->member_ids, $groups)));

        return People::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->whereIn('id', $ids)
            ->with([
                'contacts',
                'organizations' => fn ($query) => $query->select('organizations.id', 'name'),
            ])
            ->get()
            ->keyBy('id');
    }

    /**
     * @param Collection<int, People> $members
     */
    private function sharedValue(string $reason, People $keep, Collection $members): string
    {
        if ($reason !== 'email_match') {
            return $keep->getName();
        }

        $shared = $members
            ->map(fn (People $person): array => $this->normalizedEmails($person))
            ->reduce(fn (?array $carry, array $emails): array => $carry === null ? $emails : array_intersect($carry, $emails));

        return implode('; ', $shared ?? []);
    }

    /**
     * Safe when every other record is reachable through the kept one's own contact data, or carries
     * none of its own. A name alone is not enough: two people can share one.
     *
     * @param Collection<int, People> $members
     */
    private function recommendation(string $reason, People $keep, Collection $members): string
    {
        if ($reason === 'external_id_conflict') {
            return self::REVIEW_FIRST;
        }

        $keepContacts = [...$this->normalizedEmails($keep), ...$this->normalizedPhones($keep)];

        $safe = $members
            ->reject(fn (People $person): bool => $person->is($keep))
            ->every(function (People $person) use ($keepContacts): bool {
                $contacts = [...$this->normalizedEmails($person), ...$this->normalizedPhones($person)];

                return $contacts === [] || array_intersect($contacts, $keepContacts) !== [];
            });

        return $safe ? self::SAFE_TO_MERGE : self::REVIEW_FIRST;
    }

    /**
     * @return list<string>
     */
    private function differences(People $keep, People $person): array
    {
        $fields = [
            'emails' => fn (People $p): array => $this->normalizedEmails($p),
            'phones' => fn (People $p): array => $this->normalizedPhones($p),
            'organizations' => fn (People $p): array => array_map('strtolower', $this->organizationNames($p)),
        ];

        $differs = [];
        foreach ($fields as $field => $values) {
            $a = $values($keep);
            $b = $values($person);
            sort($a);
            sort($b);

            if ($a !== $b) {
                $differs[] = $field;
            }
        }

        return $differs;
    }

    /**
     * @return list<string>
     */
    private function normalizedEmails(People $person): array
    {
        return array_values(array_unique(array_map(
            fn (string $email): string => strtolower(trim($email)),
            $this->allEmails($person),
        )));
    }

    /**
     * @return list<string>
     */
    private function normalizedPhones(People $person): array
    {
        return array_values(array_filter(array_unique(array_map(
            fn (string $phone): string => preg_replace('/\D+/', '', $phone),
            $this->allPhones($person),
        ))));
    }

    /**
     * @return list<string>
     */
    private function organizationNames(People $person): array
    {
        return $person->organizations->pluck('name')->map(fn ($name): string => (string) $name)->values()->all();
    }
}
