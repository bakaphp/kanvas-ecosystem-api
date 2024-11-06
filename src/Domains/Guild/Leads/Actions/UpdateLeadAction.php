<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
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
use Kanvas\Workflow\Enums\WorkflowEnum;

class UpdateLeadAction
{
    protected bool $runWorkflow = true;

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
        return DB::transaction(function () {
            $company = $this->lead->company;
            $branch = $this->lead->company->branches()->where('id', $this->leadData->branch_id)->firstOrFail();

            $people = PeoplesRepository::getById($this->leadData->people_id, $company);

            if ($this->leadData->status_id) {
                $leadStatus = LeadStatus::getById($this->leadData->status_id);
            }

            if ($this->leadData->type_id) {
                $leadType = LeadType::getByIdFromCompany(
                    $this->leadData->type_id,
                    $company
                );
            }

            if ($this->leadData->source_id) {
                $leadSource = LeadSource::getByIdFromCompany(
                    $this->leadData->source_id,
                    $company
                );
            }

            if ($this->leadData->pipeline_stage_id) {
                $pipelineStage = PipelineStage::getById($this->leadData->pipeline_stage_id);
                $pipeline = Pipeline::getByIdFromCompany(
                    $pipelineStage->pipelines_id,
                    $company
                );
            }

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
            $lead = Lead::where('id', $this->lead->getId())
                    ->lockForUpdate()
                    ->firstOrFail();
            $lead->title = $this->leadData->title;
            $lead->people_id = $people->getId();
            $lead->firstname = $people->firstname;
            $lead->lastname = $people->lastname;
            $lead->email = $people->getEmails()->count() ? $people->getEmails()->first()->value : '';
            $lead->leads_status_id = $this->leadData->status_id ? $leadStatus->getId() : 0;
            $lead->leads_types_id = $this->leadData->type_id ? $leadType->getId() : null;
            $lead->leads_sources_id = $this->leadData->source_id ? $leadSource->getId() : null;
            $lead->pipeline_id = $this->leadData->pipeline_stage_id ? $pipeline->getId() : 0;
            $lead->pipeline_stage_id = $this->leadData->pipeline_stage_id ? $pipelineStage->getId() : 0;
            $lead->leads_receivers_id = $receiver ? $receiver->getId() : 0;
            $lead->companies_branches_id = $branch->getId();
            $lead->description = $this->leadData->description ?? '';
            $lead->reason_lost = $this->leadData->reason_lost ?? '';
            $lead->leads_owner_id = $owner ? $owner->getId() : 0;
            $lead->organization_id = $organization ? $organization->getId() : 0;

            $lead->saveOrFail();

            $lead->setCustomFields($this->leadData->custom_fields);
            $lead->saveCustomFields();

            if ($this->leadData->files) {
                $lead->addMultipleFilesFromUrl($this->leadData->files);
            }

            if ($this->leadAttempt) {
                $this->leadAttempt->leads_id = $lead->getId();
                $this->leadAttempt->saveOrFail();
            }

            if ($this->runWorkflow) {
                $lead->fireWorkflow(
                    WorkflowEnum::UPDATED->value,
                    true
                );
            }

            return $lead;
        }, 5);
    }
}
