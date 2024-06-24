<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;

class SyncZohoLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected string $zohoLeadId
    ) {
    }

    public function execute(): void
    {
        $zohoService = new ZohoService($this->app, $this->company);

        try {
            $zohoLead = $zohoService->getLeadById($this->zohoLeadId);
        } catch (\Exception $e) {
            Log::error('Error getting Zoho Lead', ['error' => $e->getMessage()]);

            return;
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
                ->where('apps_custom_fields.model_name', 'Gewaer\\Models\\Leads')
                ->where('apps_custom_fields.name', CustomFieldEnum::ZOHO_LEAD_ID->value)
                ->where('apps_custom_fields.value', $this->zohoLeadId)
                ->select($table . '.*')
                ->first();
        }

        if (! $localLead) {
            return ;
        }

        $status = $zohoLead->Lead_Status;

        $leadStatus = match (true) {
            Str::contains($status, 'close') => LeadStatus::getByName('bad'),
            Str::contains($status, 'won') => LeadStatus::getByName('complete'),
            default => LeadStatus::getByName('active'),
        };

        $localLead->leads_status_id = $leadStatus->getId();
        $localLead->saveOrFail();
    }
}
