<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Orchestrator\Routing\Actions;

use Baka\Validations\EmailDomain;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingCandidate;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\RoutingTargetTypeEnum;

/**
 * Deterministic CRM match for a meeting's outside attendees, no LLM: each attendee's open leads, and
 * for an attendee with none, their organization (the one they are linked to, else the one their email
 * domain belongs to). A closed lead is never a target. An organization that already owns a matched
 * lead is dropped, so the lead isn't competing with its own account. Leads come first, most recently
 * updated first: that order is the tie-break when the classifier can't choose.
 */
class MatchSignalToRecordsAction
{
    private const int MAX_LEADS = 5;
    private const int MAX_ORGANIZATIONS = 5;

    /**
     * @param list<string> $externalEmails
     * @param list<RoutingTargetTypeEnum> $types the levels this receiver routes to
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly array $externalEmails,
        private readonly array $types,
    ) {
    }

    /**
     * @return list<RoutingCandidate>
     */
    public function execute(): array
    {
        if ($this->externalEmails === []) {
            return [];
        }

        /** @var Collection<int, People> $people */
        $people = PeoplesRepository::getByAnyContact($this->app, $this->company, $this->externalEmails)
            ->with(['contacts', 'organizations'])
            ->get();

        $leads = in_array(RoutingTargetTypeEnum::LEAD, $this->types, true) ? $this->openLeads($people) : [];

        $organizations = in_array(RoutingTargetTypeEnum::ORGANIZATION, $this->types, true)
            ? $this->organizations($people, $leads)
            : [];

        return [
            ...array_map(RoutingCandidate::forLead(...), $leads),
            ...array_map(RoutingCandidate::forOrganization(...), $organizations),
        ];
    }

    /**
     * @param Collection<int, People> $people
     *
     * @return list<Lead>
     */
    private function openLeads(Collection $people): array
    {
        if ($people->isEmpty()) {
            return [];
        }

        return Lead::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereIn('people_id', $people->pluck('id')->all())
            ->hasOpenLeadStatus($this->company)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAX_LEADS)
            ->get()
            ->all();
    }

    /**
     * @param Collection<int, People> $people
     * @param list<Lead> $leads
     *
     * @return list<Organization>
     */
    private function organizations(Collection $people, array $leads): array
    {
        $peopleWithLead = array_flip(array_map(static fn (Lead $lead): int => (int) $lead->people_id, $leads));
        $emailsWithLead = [];
        $found = [];

        foreach ($people as $person) {
            if (isset($peopleWithLead[$person->getId()])) {
                foreach ($this->emailsOf($person) as $email) {
                    $emailsWithLead[$email] = true;
                }

                continue;
            }

            foreach ($person->organizations as $organization) {
                $found[$organization->getId()] = $organization;
            }
        }

        // One lookup per domain: several attendees from the same company are the usual case.
        $emailByDomain = [];
        foreach ($this->externalEmails as $email) {
            $domain = EmailDomain::companyDomain($email);
            if ($domain !== null && ! isset($emailsWithLead[$email])) {
                $emailByDomain[$domain] ??= $email;
            }
        }

        foreach ($emailByDomain as $email) {
            foreach (Organization::findByEmailDomain($this->app, $this->company, $email) as $organization) {
                $found[$organization->getId()] = $organization;
            }
        }

        $ownedByLead = array_flip(array_map(static fn (Lead $lead): int => (int) $lead->organization_id, $leads));

        // The pivot is a raw FK that imports and merges write, so a linked organization is checked
        // against the tenant here rather than trusted.
        $organizations = array_filter(
            $found,
            fn (Organization $organization): bool => ! isset($ownedByLead[$organization->getId()])
                && (int) $organization->companies_id === $this->company->getId()
                && (int) $organization->apps_id === $this->app->getId()
                && ! $organization->is_deleted
        );

        return array_slice(array_values($organizations), 0, self::MAX_ORGANIZATIONS);
    }

    /**
     * @return list<string>
     */
    private function emailsOf(People $person): array
    {
        return $person->contacts
            ->filter(static fn (Contact $contact): bool => Contact::isEmailType((int) $contact->contacts_types_id))
            ->map(static fn (Contact $contact): string => strtolower(trim((string) $contact->value)))
            ->values()
            ->all();
    }
}
