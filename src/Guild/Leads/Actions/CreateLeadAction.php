<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadDataInput;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;

class CreateLeadAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected readonly LeadDataInput $leadData
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Lead
    {
        $newLead = new Lead();
        $newLead->leads_owner_id = $this->leadData->leads_owner_id;

        if (!$this->leadData->leads_owner_id) {
            try {
                $newLead->leads_owner_id = LeadsRepository::getDefaultReceiver($this->leadData->branch)->agents_id;
            } catch (ModelNotFoundException $e) {
            }
        }

        $newLead->users_id = $this->leadData->user->getId();
        $newLead->companies_id = $this->leadData->branch->company()->get()->getId();
        $newLead->companies_branches_id = $this->leadData->branch->getId();
        $newLead->leads_receivers_id = $this->leadData->receiver_id;
        $newLead->leads_types_id = $this->leadData->type_id;
        $newLead->leads_sources_id = $this->leadData->source_id;

        //create people

        //create partiicpant

        //create organization
        
        return $newLead;
    }
}
