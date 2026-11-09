<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Zoho\Actions\Concerns\WritesZohoRecordWithCoercion;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

/**
 * Kanvas and Zoho move together on purpose: every lead push rebuilds Lead_Status from the Kanvas
 * status row (ZohoLead::fromLead), so a Zoho-only write would be undone by the next edit to the lead.
 */
class PushGetDocsLeadStatusAction
{
    use WritesZohoRecordWithCoercion;

    public function __construct(
        protected Lead $lead,
        protected ZohoRecordsModule $leadModule,
    ) {
    }

    /**
     * @return array{moved: bool, reason?: string, status?: string}
     */
    public function execute(): array
    {
        $label = Str::trimToNull((string) $this->lead->company->get(CustomFieldEnum::ZOHO_GET_DOCS_LEAD_STATUS->value));

        if ($label === null) {
            return ['moved' => false, 'reason' => 'not_configured'];
        }

        $zohoLeadId = Str::trimToNull((string) $this->lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value));

        if ($zohoLeadId === null) {
            return ['moved' => false, 'reason' => 'lead_not_in_zoho', 'status' => $label];
        }

        if (! $this->lead->hasOpenLeadStatus()) {
            return ['moved' => false, 'reason' => 'lead_closed', 'status' => $label];
        }

        $status = $this->resolveStatus($label);

        if ((int) $this->lead->leads_status_id === $status->getId()) {
            return ['moved' => false, 'reason' => 'already_set', 'status' => $label];
        }

        // Zoho first: a rejected write leaves Kanvas untouched, so the retry runs the whole step again.
        $data = ['Lead_Status' => $label];
        $this->updateRecord($this->leadModule, $zohoLeadId, $data);

        $this->lead->leads_status_id = $status->getId();
        $this->lead->saveOrFail();

        return ['moved' => true, 'status' => $label];
    }

    /**
     * The company's own row before a shared one, matched by name the way the Zoho pull creates them.
     * Only an open row qualifies: receiving paperwork never closes a lead, whatever the tenant calls
     * the status. Whichever row is used must carry the exact Zoho label, or the next push sends
     * "New Lead".
     */
    protected function resolveStatus(string $label): LeadStatus
    {
        $app = $this->lead->app;
        $company = $this->lead->company;

        $status = LeadStatus::query()
            ->fromPublicOrCurrentApp($app)
            ->fromCompanyAndGlobal($company)
            ->whereRaw('LOWER(name) = ?', [strtolower($label)])
            ->where('is_open', true)
            ->notDeleted()
            ->orderByDesc('companies_id')
            ->orderByDesc('apps_id')
            ->first();

        $status ??= LeadStatus::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => $label,
            'is_default' => 0,
            'is_open' => true,
        ]);

        if (Str::trimToNull((string) $status->get(CustomFieldEnum::ZOHO_STATUS_NAME->value)) === null) {
            $status->set(CustomFieldEnum::ZOHO_STATUS_NAME->value, $label);
        }

        return $status;
    }
}
