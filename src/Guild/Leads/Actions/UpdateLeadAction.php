<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\DataTransferObject\LeadUpdateInput;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadAttempt;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Users\Repositories\UsersRepository;

class UpdateLeadAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected Lead $lead,
        protected readonly LeadUpdateInput $leadData,
        protected readonly UserInterface $user,
        protected ?LeadAttempt $leadAttempt = null
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Lead
    {
        $company = $this->lead->company;
        $branch = $this->lead->company->branches()->where('id', $this->leadData->branch_id)->firstOrFail();

        $people = PeoplesRepository::getById($this->leadData->people_id, $company);

        $leadStatus = LeadStatus::getById($this->leadData->status_id);

        $leadType = LeadType::getByIdFromCompany(
            $this->leadData->type_id,
            $company
        );
        $leadSource = LeadSource::getByIdFromCompany(
            $this->leadData->source_id,
            $company
        );

        $pipelineStage = PipelineStage::getById($this->leadData->pipeline_stage_id);
        $pipeline = Pipeline::getByIdFromCompany(
            $pipelineStage->pipelines_id,
            $company
        );

        $receiver = null;
        if ($this->leadData->receiver_id) {
            $receiver = LeadReceiver::getByIdFromBranch(
                $this->leadData->receiver_id,
                $branch
            );
        }

        $owner = null;
        if ($this->leadData->leads_owner_id) {
            $owner = UsersRepository::getUserOfCompanyById($company, $this->leadData->leads_owner_id);
        }

        $organization = null;
        if ($this->leadData->organization_id) {
            $organization = Organization::getByIdFromCompany(
                $this->leadData->organization_id,
                $company
            );
        }

        //cant understand why db connection is switching to another db
        $lead = Lead::getById($this->lead->getId());
        $lead->title = $this->leadData->title;
        $lead->people_id = $people->getId();
        $lead->firstname = $people->firstname;
        $lead->lastname = $people->lastname;
        $lead->email = $people->getEmails()->count() ? $people->getEmails()->first()->value : '';
        $lead->leads_status_id = $leadStatus->getId();
        $lead->leads_types_id = $leadType->getId();
        $lead->leads_sources_id = $leadSource->getId();
        $lead->pipeline_id = $pipeline->getId();
        $lead->pipeline_stage_id = $pipelineStage->getId();
        $lead->leads_receivers_id = $receiver ? $receiver->getId() : 0;
        $lead->companies_branches_id = $branch->getId();
        $lead->description = $this->leadData->description ?? '';
        $lead->reason_lost = $this->leadData->reason_lost ?? '';
        $lead->leads_owner_id = $owner ? $owner->getId() : 0;
        $lead->organization_id = $organization ? $organization->getId() : 0;

        $lead->saveOrFail();

        $lead->setCustomFields($this->leadData->custom_fields);
        $lead->saveCustomFields();

        if ($this->leadAttempt) {
            $this->leadAttempt->leads_id = $lead->getId();
            $this->leadAttempt->saveOrFail();
        }

        /**
         * @psalm-suppress LessSpecificReturnStatement
         */
        return $lead;
    }
}
