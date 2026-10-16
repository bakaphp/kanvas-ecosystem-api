<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Contracts\CrmLeadDriver;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadPlan;
use Kanvas\Guild\Leads\DataTransferObject\CrmLeadSync;
use Kanvas\Guild\Leads\Enums\CrmLeadSyncOutcomeEnum;
use Kanvas\Guild\Leads\Exceptions\CrmLeadSyncException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;

/**
 * Decides what a sync will do. Reads only: nothing is written until the plan has been checked.
 */
class ResolveCrmLeadPlanAction
{
    public function __construct(
        protected readonly CrmLeadSync $data,
        protected readonly CrmLeadDriver $driver
    ) {
    }

    public function execute(): CrmLeadPlan
    {
        $lead = $this->leadByCrmId();

        if ($lead instanceof Lead) {
            $people = $lead->people;
            $this->assertSameCustomer($people);

            return new CrmLeadPlan(CrmLeadSyncOutcomeEnum::OPENED, $people, $lead);
        }

        $match = new FindCrmPeopleAction($this->data, $this->driver)->execute();

        if ($match === null) {
            return new CrmLeadPlan($this->withoutLeadId() ? CrmLeadSyncOutcomeEnum::PEOPLE_ONLY : CrmLeadSyncOutcomeEnum::CREATED_ALL);
        }

        $lead = $this->activeLead($match->people);

        if (! $lead instanceof Lead) {
            return new CrmLeadPlan(
                outcome: $this->withoutLeadId() ? CrmLeadSyncOutcomeEnum::PEOPLE_ONLY : CrmLeadSyncOutcomeEnum::CREATED_LEAD,
                people: $match->people,
                ambiguous: $match->ambiguous
            );
        }

        $outcome = $this->withoutLeadId()
            ? CrmLeadSyncOutcomeEnum::OPENED
            : CrmLeadSyncOutcomeEnum::ADOPTED;

        return new CrmLeadPlan(
            outcome: $outcome,
            people: $match->people,
            lead: $lead,
            ambiguous: $match->ambiguous
        );
    }

    /**
     * A lead is never created without the CRM's id for it: with no id the call opens a lead that
     * already exists, and otherwise only the people is made.
     */
    private function withoutLeadId(): bool
    {
        return $this->data->identity->lead_id === null;
    }

    private function leadByCrmId(): ?Lead
    {
        $leadId = $this->data->identity->lead_id;

        if ($leadId === null) {
            return null;
        }

        /** @var Lead|null $lead */
        $lead = Lead::getByCustomFieldBuilderTransactionSafe($this->driver->leadIdField(), $leadId, $this->data->company)
            ->fromApp($this->data->app)
            ->fromCompany($this->data->company)
            ->notDeleted()
            ->orderBy('id')
            ->first();

        return $lead;
    }

    /**
     * The lead the CRM's id names belongs to a customer who already carries another id of this CRM:
     * the ids contradict each other and nothing is guessed.
     */
    private function assertSameCustomer(?People $people): void
    {
        $field = $this->driver->clientIdField();

        if ($field === null || ! $people instanceof People) {
            return;
        }

        $stored = Str::trimToNull((string) $people->get($field));

        if ($stored !== null && $stored !== $this->data->identity->client_id) {
            throw CrmLeadSyncException::clientLeadMismatch();
        }
    }

    /**
     * The most recently updated active lead of the people, "active" being the company's own
     * `mapping_status_crm` list. With a CRM lead id in hand, a lead that already carries a different one
     * is another prospect and is skipped; without one there is nothing to contradict.
     */
    private function activeLead(People $people): ?Lead
    {
        $incoming = $this->data->identity->lead_id;

        $leads = LeadsRepository::getPeopleActiveLeads($people)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        foreach ($leads as $lead) {
            if ($incoming === null) {
                return $lead;
            }

            $stored = Str::trimToNull((string) $lead->get($this->driver->leadIdField()));

            if ($stored === null || $stored === $incoming) {
                return $lead;
            }
        }

        return null;
    }
}
