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

class SyncZohoLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected LeadReceiver $receiver,
        protected string $zohoLeadId
    ) {
    }

    public function execute(): ?Lead
    {
        $zohoService = new ZohoService($this->app, $this->company);

        try {
            $zohoLead = $zohoService->getLeadById($this->zohoLeadId);
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

        $status = strtolower($zohoLead->Lead_Status);

        $leadStatus = match (true) {
            Str::contains($status, 'close') => LeadStatus::getByName('bad'),
            Str::contains($status, 'won') => LeadStatus::getByName('complete'),
            default => LeadStatus::getByName('active'),
        };

        $user = UsersAssociatedApps::fromApp($this->app)->where('email', $zohoLead->Owner['email'])->first();

        if (! $localLead) {
            //create lead
            $pipelineStage = Pipeline::fromApp($this->app)->fromCompany($this->company)->where('is_default', 1)->first()->stages()->first();

            $contact = [
                [
                    'value' => $zohoLead->Email,
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],[
                    'value' => $zohoLead->Phone,
                    'contacts_types_id' => 2,
                    'weight' => 0,
                ],
            ];
            $lead = new DataTransferObjectLead(
                $this->app,
                $this->company->defaultBranch,
                $user ?? $this->company->user,
                $zohoLead->Full_Name,
                $pipelineStage->getId(),
                new People(
                    $this->app,
                    $this->company->defaultBranch,
                    $user ?? $this->company->user,
                    $zohoLead->First_Name,
                    Contact::collect($contact, DataCollection::class),
                    Address::collect([], DataCollection::class),
                    $zohoLead->Last_Name
                ),
                $user ? $user->getId() : 0,
                0,
                $leadStatus->getId(),
                0,
                $this->receiver->getId(),
                null,
                null,
                null,
                [
                    CustomFieldEnum::ZOHO_LEAD_ID->value => $this->zohoLeadId,
                ],
                [],
                true
            );

            return (new CreateLeadAction($lead))->execute();
        }

        if ($user) {
            $localLead->leads_owner_id = $user->getId();
        }
        $localLead->people->firstname = $zohoLead->First_Name;
        $localLead->people->lastname = $zohoLead->Last_Name;
        $localLead->firstname = $zohoLead->First_Name;
        $localLead->lastname = $zohoLead->Last_Name;
        $localLead->title = $zohoLead->Full_Name . ' Opp';
        $localLead->leads_status_id = $leadStatus->getId();
        $localLead->disableWorkflows();
        $localLead->saveOrFail();

        return $localLead;
    }
}
