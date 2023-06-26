<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
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
        protected readonly ?LeadAttempt $leadAttempt = null
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Lead
    {
        $company = $this->lead->company()->firstOrFail();
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

        $this->lead->title = $this->leadData->title;
        $this->lead->people_id = $people->getId();
        $this->lead->firstname = $people->firstname;
        $this->lead->lastname = $people->lastname;
        //$this->lead->email = $people->getemai
        $this->lead->leads_status_id = $leadStatus->getId();
        $this->lead->leads_types_id = $leadType->getId();
        $this->lead->leads_sources_id = $leadSource->getId();
        $this->lead->pipeline_id = $pipeline->getId();
        $this->lead->pipeline_stage_id = $pipelineStage->getId();
        $this->lead->leads_receivers_id = $receiver ? $receiver->getId() : 0;
        $this->lead->companies_branches_id = $branch->getId();
        $this->lead->description = $this->leadData->description ?? '';
        $this->lead->reason_lost = $this->leadData->reason_lost ?? '';
        $this->lead->leads_owner_id = $owner ? $owner->getId() : 0;
        $this->lead->organization_id = $organization ? $organization->getId() : 0;

        $this->lead->saveOrFail();

        if ($this->leadAttempt) {
            $this->leadAttempt->leads_id = $this->lead->getId();
            $this->leadAttempt->saveOrFail();
        }

        return $this->lead;
    }
}
