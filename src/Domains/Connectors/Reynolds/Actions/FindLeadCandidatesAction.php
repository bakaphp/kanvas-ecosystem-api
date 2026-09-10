<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleMatchScore;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\LeadPullResultService;

/**
 * Finds the leads already in Kanvas that could be the Reynolds prospect the
 * caller is describing, ranked so a human can pick.
 *
 * Reynolds has no pull API — prospects arrive through the OSL/LDU webhook — so
 * unlike every other connector's "pull", this searches our own database and
 * never reaches out. It is deliberately READ-ONLY: it does not create, close or
 * re-status anything, which is what makes it safe to re-run from an interactive
 * picker. Do not add a write here; the client has an explicit attach mutation
 * for that.
 *
 * Takes Apps rather than AppInterface because the People contact-search helpers
 * it delegates to require the concrete model.
 */
class FindLeadCandidatesAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> LeadPullResultService shape, best match first
     */
    public function execute(
        ?string $clientId = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $firstname = null,
        ?string $lastname = null,
    ): array {
        if ($clientId === null && empty($email) && empty($phone)) {
            return [];
        }

        /** @var array<int, array{lead: Lead, rank: float, anchored: bool}> $candidates */
        $candidates = [];

        foreach ($this->leadsAnchoredOnClientId($clientId) as $lead) {
            // An external id match is identity, not similarity. Ranking it 1.0
            // rather than sorting on a separate flag keeps the wire shape free of
            // a match-reason field the client would have to understand.
            $candidates[$lead->getId()] = ['lead' => $lead, 'rank' => 1.0, 'anchored' => true];
        }

        foreach ($this->peopleMatchingContacts($email, $phone) as $people) {
            $rank = PeopleMatchScore::for(
                $people,
                firstname: $firstname,
                lastname: $lastname,
                phones: [$phone],
                emails: [$email],
            )->value;

            foreach (LeadsRepository::getPeopleNonClosedLeads($people)->get() as $lead) {
                if (isset($candidates[$lead->getId()])) {
                    continue;
                }

                $candidates[$lead->getId()] = ['lead' => $lead, 'rank' => $rank, 'anchored' => false];
            }
        }

        $candidates = array_values($candidates);

        usort(
            $candidates,
            fn (array $a, array $b) => [$b['rank'], $b['anchored'], $b['lead']->getId()]
                <=> [$a['rank'], $a['anchored'], $a['lead']->getId()]
        );

        return array_map(
            fn (array $candidate) => LeadPullResultService::toArray($candidate['lead'], $candidate['rank']),
            $candidates
        );
    }

    /**
     * @return Collection<int, Lead>
     */
    private function leadsAnchoredOnClientId(?string $clientId): Collection
    {
        if ($clientId === null) {
            return collect();
        }

        $closedStatuses = LeadsRepository::closedStatusNames($this->company);

        // The transaction-safe variant is required, not preferred: the plain one
        // joins apps_custom_fields across connections and returns nothing,
        // nondeterministically, for a field written inside the caller's own
        // transaction — which is every test that stamps CLIENT_ID then reads it.
        return Lead::getByCustomFieldBuilderTransactionSafe(
            CustomFieldEnum::CLIENT_ID->value,
            $clientId,
            $this->company
        )
            ->fromApp($this->app)
            ->notDeleted()
            ->whereHas('status', fn ($query) => $query->whereNotIn('name', $closedStatuses))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, People>
     */
    private function peopleMatchingContacts(?string $email, ?string $phone): Collection
    {
        $people = collect();

        if (! empty($phone)) {
            $people = $people
                ->merge(People::getAllByPhoneMatchingValue($phone, $this->company, $this->app))
                ->merge(People::getAllByMatchingValue($phone, $this->company, $this->app));
        }

        if (! empty($email)) {
            $people = $people->merge(People::getAllByMatchingValue($email, $this->company, $this->app));
        }

        return $people->unique('id');
    }
}
