<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadDataInput;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadAttempt;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Leads\Notifications\NewLeadCompanyOwnerNotification;
use Kanvas\Guild\Leads\Notifications\NewLeadNotification;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Kanvas\Users\Services\UserRoleNotificationService;
use Kanvas\Workflow\Enums\WorkflowEnum;

use function Sentry\captureException;

class CreateLeadAction
{
    protected CompanyInterface $company;
    protected bool $runWorkflow = true;

    /**
     * __construct.
     */
    public function __construct(
        protected readonly LeadDataInput $leadData,
        protected ?LeadAttempt $leadAttempt = null
    ) {
        $this->company = $this->leadData->branch->company()->firstOrFail();
    }

    /**
     * execute.
     */
    public function execute(): Lead
    {
        return DB::transaction(function () {
            $newLead = new Lead();
            $newLead->leads_owner_id = $this->leadData->leads_owner_id;
            $organization = null;

            if (!$this->leadData->leads_owner_id) {
                try {
                    $newLead->leads_owner_id = LeadsRepository::getDefaultReceiver($this->leadData->branch)->agents_id;
                } catch (ModelNotFoundException $e) {
                }
            }

            $newLead->apps_id = $this->leadData->app->getId();
            $newLead->users_id = $this->leadData->user->getId();
            $newLead->companies_id = $this->company->getId();
            $newLead->companies_branches_id = $this->leadData->branch->getId();
            $newLead->leads_receivers_id = $this->leadData->receiver_id;
            $newLead->leads_types_id = $this->leadData->type_id;
            $newLead->leads_sources_id = $this->leadData->source_id;
            $newLead->title = $this->leadData->title ?? $this->leadData->people->firstname.' '.$this->leadData->people->lastname;
            $newLead->firstname = $this->leadData->people->firstname;
            $newLead->lastname = $this->leadData->people->lastname;
            $newLead->description = $this->leadData->description;
            $newLead->leads_status_id = $this->leadData->status_id;
            $newLead->reason_lost = $this->leadData->reason_lost;

            //create people
            $people = (new CreatePeopleAction($this->leadData->people))->execute();
            $newLead->people_id = $people->getId();
            $newLead->email = $people->getEmails()->isNotEmpty() ? $people->getEmails()->first()?->value : null;
            $newLead->phone = $people->getPhones()->isNotEmpty() ? $people->getPhones()->first()?->value : null;

            if (!$this->leadData->runWorkflow) {
                $newLead->disableWorkflows();
            }

            if ($this->company->get(FlagEnum::COMPANY_CANT_HAVE_MULTIPLE_OPEN_LEADS->value)) {
                $this->checkIfLeadExist($newLead, $people);
            }

            if ($this->leadData->organization instanceof Organization) {
                $organization = (new CreateOrganizationAction($this->leadData->organization))->execute();
                $newLead->organization_id = $organization->getId();
            }
            $newLead->saveOrFail();

            $newLead->setCustomFields($this->leadData->custom_fields);
            $newLead->saveCustomFields();

            if ($this->leadData->files) {
                $newLead->addMultipleFilesFromUrl($this->leadData->files);
            }

            //create organization
            if ($organization) {
                $organization->addPeople($people);
            }

            if ($this->leadAttempt instanceof LeadAttempt) {
                $this->leadAttempt->leads_id = $newLead->getId();
                $this->leadAttempt->processed = 1;
                $this->leadAttempt->saveOrFail();
            }

            if ($this->runWorkflow) {
                $newLead->fireWorkflow(
                    WorkflowEnum::CREATED->value,
                    true
                );
            }

            try {
                /**
                 * @todo move this notifications to workflow
                 */
                /*   $newLead->owner?->notify(new NewLeadNotification($newLead, [
                      'app' => $newLead->app,
                      'company' => $newLead->company,
                  ]));

                  UserRoleNotificationService::notify(
                      RolesEnums::ADMIN->value,
                      new NewLeadCompanyOwnerNotification(
                          $newLead,
                          [
                              'app' => $newLead->app,
                              'company' => $newLead->company,
                          ]
                      ),
                      $newLead->app
                  ); */
            } catch (Exception $e) {
                captureException($e);
            }

            return $newLead;
        }, 5);
    }

    protected function checkIfLeadExist(Lead $lead, People $people): void
    {
        $duplicate = Lead::query()->fromApp($this->leadData->app)
            ->fromCompany($this->company)
            ->notDeleted(StateEnums::NO->getValue())
            ->where([
                ['people_id', $people->getId()],
                ['leads_status_id', $this->leadData->status_id ?: LeadStatus::getDefault()->getId()],
            ])
            ->lockForUpdate()
            ->exists();

        if ($duplicate) {
            $lead->setDuplicate();
        }
    }
}
