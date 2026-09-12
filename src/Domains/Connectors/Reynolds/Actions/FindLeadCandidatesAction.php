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
use Kanvas\Guild\Leads\Services\LeadPullResult;

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
     * @return array<int, array<string, mixed>> LeadPullResult shape, best match first
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

        /** @var array<int, LeadPullResult> $candidates */
        $candidates = [];
        /** @var array<int, true> $anchoredIds */
        $anchoredIds = [];

        foreach ($this->leadsAnchoredOnClientId($clientId) as $lead) {
            // An external id match is identity, not similarity, so it takes the
            // top rank. It still needs its own tiebreak: a lone exact email also
            // scores 1.0 (one term supplied, one matched), and without this the
            // newer of the two wins on id and the anchor loses its own search.
            // Tracked beside the results rather than on LeadPullResult — it is
            // Reynolds' notion of a match, and it must not reach the wire.
            $candidates[$lead->getId()] = LeadPullResult::for($lead, 1.0);
            $anchoredIds[$lead->getId()] = true;
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

                $candidates[$lead->getId()] = LeadPullResult::for($lead, $rank);
            }
        }

        $candidates = array_values($candidates);

        usort(
            $candidates,
            fn (LeadPullResult $a, LeadPullResult $b) => [
                $b->rank,
                isset($anchoredIds[$b->lead->getId()]),
                $b->lead->getId(),
            ] <=> [
                $a->rank,
                isset($anchoredIds[$a->lead->getId()]),
                $a->lead->getId(),
            ]
        );

        return array_map(fn (LeadPullResult $candidate) => $candidate->toArray(), $candidates);
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
