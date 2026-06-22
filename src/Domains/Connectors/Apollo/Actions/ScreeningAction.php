<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Client as ApolloClient;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\ContactType;
use Kanvas\Guild\Customers\Models\People;

class ScreeningAction
{
    public function __construct(
        protected People $people,
        protected Apps $app
    ) {
    }

    /**
     * Apollo's /people/match resolves to a SINGLE record, largely keyed off the
     * email (or LinkedIn URL) we send. A person with several emails can have a
     * sparse/junk record under one address and the real enriched record under
     * another — so betting on the first email silently under-enriches them.
     *
     * We try each selector (LinkedIn first, then every email) and keep the first
     * match that actually carries data, falling back to whatever bare match we
     * saw so callers still get the echoed person on a credit-limited key.
     */
    public function execute(): array
    {
        $client = new ApolloClient($this->app);
        $fallback = [];

        foreach ($this->buildMatchCandidates() as $candidate) {
            $person = $client->post('/people/match', $candidate)['person'] ?? [];

            if (EnrichPeopleFromApolloAction::hasMeaningfulEnrichment($person)) {
                return $person;
            }

            if (empty($fallback) && ! empty($person)) {
                $fallback = $person;
            }
        }

        return $fallback;
    }

    /**
     * Ordered list of /people/match payloads to try, most-confident selector
     * first. Public so the selector logic can be asserted without a live call.
     *
     * @return list<array<string, string>>
     */
    public function buildMatchCandidates(): array
    {
        $base = [
            'first_name' => $this->people->firstname,
            'last_name' => $this->people->lastname,
            'name' => $this->people->getName(),
        ];

        $candidates = [];

        $linkedin = $this->people->contacts()
            ->where('contacts_types_id', ContactType::getByName('LinkedIn')->getId())
            ->value('value');

        if (! empty($linkedin)) {
            $candidates[] = $base + ['linkedin_url' => $linkedin];
        }

        foreach ($this->collectEmails() as $email) {
            $candidates[] = $base + ['email' => $email];
        }

        if (empty($candidates)) {
            $candidates[] = $base;
        }

        return $candidates;
    }

    /**
     * Every email the person has, across all email contact types (the Intras
     * importer stores office/personal/assistant addresses under EMAIL,
     * PRIMARY_EMAIL and SECONDARY_EMAIL), de-duplicated and primary-first.
     *
     * @return list<string>
     */
    private function collectEmails(): array
    {
        $emailTypeIds = [
            ContactTypeEnum::PRIMARY_EMAIL->value,
            ContactTypeEnum::EMAIL->value,
            ContactTypeEnum::SECONDARY_EMAIL->value,
        ];

        return $this->people->contacts()
            ->whereIn('contacts_types_id', $emailTypeIds)
            ->orderByRaw('FIELD(contacts_types_id, ' . implode(',', $emailTypeIds) . ')')
            ->orderBy('weight')
            ->pluck('value')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
