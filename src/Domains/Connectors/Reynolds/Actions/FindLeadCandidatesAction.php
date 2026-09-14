<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Actions;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleMatchScore;
use Kanvas\Guild\Leads\DataTransferObject\LeadCandidate;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;

/**
 * Reynolds has no pull API — prospects arrive by webhook — so this ranks leads already in
 * Kanvas instead of calling out. Read-only on purpose so the picker can re-run it; writes
 * belong to the client's attach mutation.
 */
class FindLeadCandidatesAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> LeadCandidate shape, best match first
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

        /** @var array<int, LeadCandidate> $candidates */
        $candidates = [];
        /** @var array<int, true> $anchoredIds */
        $anchoredIds = [];

        foreach ($this->leadsAnchoredOnClientId($clientId) as $lead) {
            // A client-id match is identity, so it must outrank a lone exact email that also scores 1.0.
            $candidates[$lead->getId()] = new LeadCandidate($lead, 1.0);
            $anchoredIds[$lead->getId()] = true;
        }

        $matchedPeople = People::getAllByPhoneOrEmail(
            $phone,
            $email,
            $this->company,
            $this->app
        );

        foreach ($matchedPeople as $people) {
            $rank = PeopleMatchScore::for(
                $people,
                firstname: $firstname,
                lastname: $lastname,
                phones: [$phone],
                emails: [$email],
            )->value;

            foreach (LeadsRepository::getPeopleNonClosedLeads($people)->get() as $lead) {
                $candidates[$lead->getId()] ??= new LeadCandidate($lead, $rank);
            }
        }

        $candidates = array_values($candidates);

        usort(
            $candidates,
            fn (LeadCandidate $a, LeadCandidate $b) => [
                $b->rank,
                isset($anchoredIds[$b->lead->getId()]),
                $b->lead->getId(),
            ] <=> [
                $a->rank,
                isset($anchoredIds[$a->lead->getId()]),
                $a->lead->getId(),
            ]
        );

        return array_map(fn (LeadCandidate $candidate) => $candidate->toArray(), $candidates);
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

        // The plain builder joins apps_custom_fields across connections and misses a field
        // written inside the caller's still-open transaction.
        return Lead::getByCustomFieldBuilderTransactionSafe(CustomFieldEnum::CLIENT_ID->value, $clientId, $this->company)
            ->fromApp($this->app)
            ->notDeleted()
            ->whereHas('status', fn ($query) => $query->whereNotIn('name', $closedStatuses))
            ->orderByDesc('id')
            ->get();
    }
}
