<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadDataInput;
use Kanvas\Guild\Leads\DataTransferObject\LeadsParticipant;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Spatie\LaravelData\DataCollection;

class CreateLeadAction
{
    protected CompanyInterface $company;

    /**
     * __construct.
     */
    public function __construct(
        protected readonly LeadDataInput $leadData
    ) {
        $this->company = $this->leadData->branch->company()->firstOrFail();
    }

    /**
     * execute.
     */
    public function execute(): Lead
    {
        $newLead = new Lead();
        $newLead->leads_owner_id = $this->leadData->leads_owner_id;

        if (! $this->leadData->leads_owner_id) {
            try {
                $newLead->leads_owner_id = LeadsRepository::getDefaultReceiver($this->leadData->branch)->agents_id;
            } catch (ModelNotFoundException $e) {
            }
        }

        $newLead->users_id = $this->leadData->user->getId();
        $newLead->companies_id = $this->company->getId();
        $newLead->companies_branches_id = $this->leadData->branch->getId();
        $newLead->leads_receivers_id = $this->leadData->receiver_id;
        $newLead->leads_types_id = $this->leadData->type_id;
        $newLead->leads_sources_id = $this->leadData->source_id;

        //create people
        $people = (new CreatePeopleAction($this->leadData->people))->execute();
        $newLead->people_id = $people->getId();

        $newLead->saveOrFail();

        //create participant
        if ($this->leadData->participants instanceof DataCollection && $this->leadData->participants->count()) {
            foreach ($this->leadData->participants as $partipantData) {
                $participant = (new CreatePeopleAction($partipantData))->execute();
                $addLeadParticipant = new AddLeadParticipantAction(
                    new LeadsParticipant(
                        $this->leadData->app,
                        $this->company,
                        $this->leadData->user,
                        $newLead,
                        $participant,
                        PeoplesRepository::getRelationshipTypeById($partipantData->participants_types_id, $this->company)
                    )
                );
                $addLeadParticipant->execute();
            }
        }

        //create organization

        return $newLead;
    }
}
