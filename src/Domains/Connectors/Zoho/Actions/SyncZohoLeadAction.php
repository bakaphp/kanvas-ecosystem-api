<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Users\Models\UsersAssociatedApps;
use Spatie\LaravelData\DataCollection;
use Webleit\ZohoCrmApi\Models\Record;

class SyncZohoLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected LeadReceiver $receiver,
        protected string $zohoLeadId
    ) {
    }

    public function execute(?Record $zohoLead = null): ?Lead
    {
        $zohoService = new ZohoService($this->app, $this->company);

        try {
            $zohoLead = $zohoLead === null ? $zohoService->getLeadById($this->zohoLeadId) : $zohoLead;
        } catch (Exception $e) {
            Log::error('Error getting Zoho Lead', ['error' => $e->getMessage()]);

            return null;
        }

        $localLead = Lead::getByCustomField(
            CustomFieldEnum::ZOHO_LEAD_ID->value,
            $this->zohoLeadId,
            $this->company
        );

        if (! $localLead) {
            $table = (new Lead())->getTable();
            $localLead = Lead::join(DB::connection('ecosystem')->getDatabaseName() . '.apps_custom_fields', 'apps_custom_fields.entity_id', '=', $table . '.id')
                ->where('apps_custom_fields.companies_id', $this->company->getId())
                ->where('apps_custom_fields.model_name', 'Gewaer\\Models\\Leads') //legacy
                ->where('apps_custom_fields.name', CustomFieldEnum::ZOHO_LEAD_ID->value)
                ->where('apps_custom_fields.value', $this->zohoLeadId)
                ->select($table . '.*')
                ->first();
        }

        $status = ! empty($zohoLead->Lead_Status) ? strtolower($zohoLead->Lead_Status) : '';

        $leadStatus = match (true) {
            Str::contains($status, 'close') => LeadStatus::getByName('bad'),
            Str::contains($status, 'won') => LeadStatus::getByName('complete'),
            Str::contains($status, 'duplicate') => LeadStatus::getByName('complete'),
            default => LeadStatus::getByName('active'),
        };

        $user = UsersAssociatedApps::fromApp($this->app)->where('email', $zohoLead->Owner['email'])->first()?->user;

        if (! $localLead) {
            //create lead
            $pipelineStage = Pipeline::fromApp($this->app)->fromCompany($this->company)->where('is_default', 1)->first()->stages()->first();

            $contact = [];

            if (! empty($zohoLead->Email)) {
                $contact[] = [
                    'value' => $zohoLead->Email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ];
            }

            if (! empty($zohoLead->Phone)) {
                $contact[] = [
                    'value' => $zohoLead->Phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ];
            }

            /**
             * @todo assign owner and user and member # if exist
             */
            $lead = new DataTransferObjectLead(
                app: $this->app,
                branch: $this->company->defaultBranch,
                user: $user ?? $this->company->user,
                title: $zohoLead->Full_Name,
                pipeline_stage_id: $pipelineStage->getId(),
                people: new People(
                    $this->app,
                    $this->company->defaultBranch,
                    $user ?? $this->company->user,
                    $zohoLead->First_Name,
                    Contact::collect($contact, DataCollection::class),
                    Address::collect([], DataCollection::class),
                    $zohoLead->Last_Name
                ),
                leads_owner_id: $user ? $user->getId() : 0,
                status_id: $leadStatus->getId(),
                receiver_id: $this->receiver->getId(),
                custom_fields: [
                    CustomFieldEnum::ZOHO_LEAD_ID->value => $this->zohoLeadId,
                ],
                runWorkflow: false
            );

            return (new CreateLeadAction($lead))->execute();
        }

        if ($user) {
            $localLead->leads_owner_id = $user->getId();
        }

        if ($user) {
            $localLead->leads_owner_id = $user->getId();
            $localLead->users_id = $user->getId();
        }

        $localLead->people->firstname = $zohoLead->First_Name;
        $localLead->people->lastname = $zohoLead->Last_Name;
        $localLead->firstname = $zohoLead->First_Name;
        $localLead->lastname = $zohoLead->Last_Name;
        $localLead->title = $zohoLead->Full_Name;
        $localLead->description = $zohoLead->Description;
        $localLead->leads_status_id = $leadStatus->getId();
        $localLead->disableWorkflows();
        $localLead->saveOrFail();

        return $localLead;
    }
}
