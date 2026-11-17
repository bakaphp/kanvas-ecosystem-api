<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Connectors\SalesAssist\Contracts\CrmLeadDriver;
use Kanvas\Connectors\SalesAssist\DataTransferObject\Crm\CrmCustomer;
use Kanvas\Connectors\SalesAssist\DataTransferObject\Crm\CrmParticipantAdd;
use Kanvas\Connectors\SalesAssist\DataTransferObject\Crm\CrmPhones;
use Kanvas\Connectors\SalesAssist\DataTransferObject\CrmLeadSync;
use Kanvas\Connectors\SalesAssist\Exceptions\CrmLeadSyncException;
use Kanvas\Connectors\SalesAssist\Services\NameAndContactPeopleFinder;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleRelationship;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Actions\AddLeadParticipantAction;
use Kanvas\Guild\Leads\Actions\RemoveLeadParticipantAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadParticipant;

/**
 * Adds and removes the participants a request lists. `prepare` checks every row against the company
 * before anything is written; `execute` then writes them.
 */
class SyncCrmLeadParticipantsAction
{
    /** @var array<int, PeopleRelationship> */
    private array $relationships = [];

    /** @var array<int, People> */
    private array $removals = [];

    public function __construct(
        protected readonly CrmLeadSync $data,
        protected readonly CrmLeadDriver $driver,
        protected readonly ?Lead $lead
    ) {
    }

    /**
     * @throws CrmLeadSyncException
     */
    public function prepare(): void
    {
        $participants = $this->data->participants;

        foreach ($participants?->add ?? [] as $index => $row) {
            $this->relationships[$index] = $this->relationship($row->relationship_id, "participants.add.{$index}.relationship_id");
        }

        foreach ($participants?->remove ?? [] as $index => $row) {
            $field = "participants.remove.{$index}";
            $people = $this->person((int) $row->people_id, $field);

            if (! $this->lead instanceof Lead || ! $this->isParticipant($this->lead, $people)) {
                throw CrmLeadSyncException::invalidParticipant('That person is not a participant of this lead', $field);
            }

            $this->removals[$index] = $people;
        }
    }

    /**
     * @return list<array{code: string, field: string}> warnings
     */
    public function execute(Lead $lead): array
    {
        $warnings = [];

        foreach ($this->data->participants?->add ?? [] as $index => $row) {
            $people = $this->resolvePeople($row);

            if ($people === null) {
                $warnings[] = ['code' => 'participant_not_found', 'field' => "participants.add.{$index}"];

                continue;
            }

            $this->add($lead, $people, $this->relationships[$index]);
        }

        foreach ($this->removals as $people) {
            new RemoveLeadParticipantAction($this->participant($lead, $people))->execute();
        }

        return $warnings;
    }

    private function add(Lead $lead, People $people, PeopleRelationship $relationship): void
    {
        $action = new AddLeadParticipantAction($this->participant($lead, $people, $relationship));
        $action->runWorkflow = false;
        $action->execute();
    }

    private function participant(Lead $lead, People $people, ?PeopleRelationship $relationship = null): LeadsParticipant
    {
        return new LeadsParticipant(
            $this->data->app,
            $this->data->company,
            $this->data->user,
            $lead,
            $people,
            $relationship
        );
    }

    /**
     * The same name and a shared contact is the same person, so a participant sent twice is not made
     * twice. Null when nobody matches and the CRM cannot create.
     */
    private function resolvePeople(CrmParticipantAdd $row): ?People
    {
        $customer = new CrmCustomer(
            firstname: $row->firstname,
            lastname: $row->lastname,
            phones: new CrmPhones(cell: $row->phone),
            emails: $row->email === null ? null : [$row->email]
        );

        $people = new NameAndContactPeopleFinder()->find($this->data->app, $this->data->company, $customer)->first();

        if ($people === null && ! $this->driver->canCreate()) {
            return null;
        }

        $people ??= new CreateCrmPeopleAction(
            $this->data->app,
            $this->data->company,
            $this->data->user,
            $customer
        )->execute();

        new ApplyCrmCustomerAction($people, $customer, $this->data->app)->execute();

        return $people;
    }

    private function relationship(string $id, string $field): PeopleRelationship
    {
        try {
            return PeoplesRepository::getRelationshipTypeById((int) $id, $this->data->company);
        } catch (ModelNotFoundException) {
            throw CrmLeadSyncException::invalidParticipant('That relationship does not belong to this company', $field);
        }
    }

    private function person(int $id, string $field): People
    {
        try {
            /** @var People $people */
            $people = PeoplesRepository::getById($id, $this->data->company);

            return $people;
        } catch (ModelNotFoundException) {
            throw CrmLeadSyncException::invalidParticipant('That person does not belong to this company', $field);
        }
    }

    private function isParticipant(Lead $lead, People $people): bool
    {
        return LeadParticipant::query()
            ->where('leads_id', $lead->getId())
            ->where('peoples_id', $people->getId())
            ->exists();
    }
}
