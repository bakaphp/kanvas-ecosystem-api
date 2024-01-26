<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Throwable;
use Webleit\ZohoCrmApi\Modules\Leads as ZohoLeadModule;
use Workflow\Activity;

class ZohoLeadActivity extends Activity implements WorkflowActivityInterface
{
    /**
     * @param Lead $lead
     */
    public function execute(Model $lead, AppInterface $app, array $params): array
    {
        $zohoLead = ZohoLead::fromLead($lead);
        $zohoData = $zohoLead->toArray();
        $company = $lead->company()->firstOrFail();
        $usesAgentsModule = $company->get(CustomFieldEnum::ZOHO_HAS_AGENTS_MODULE->value);

        $zohoCrm = Client::getInstance($app, $company);

        if ($usesAgentsModule) {
            $this->assignAgent($app, $zohoLead, $lead, $company, $zohoData);
        }

        if (! $zohoLeadId = $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value)) {
            $zohoLead = $zohoCrm->leads->create($zohoData);
            $zohoLeadId = $zohoLead->getId();

            $lead->set(
                CustomFieldEnum::ZOHO_LEAD_ID->value,
                $zohoLeadId
            );
        } else {
            $zohoLead = $zohoCrm->leads->update(
                (string) $zohoLeadId,
                $zohoData
            );
        }

        $this->uploadAttachments($zohoCrm->leads, $lead);

        return [
            'zohoLeadId' => $zohoLeadId,
            'zohoRequest' => $zohoData,
            'leadId' => $lead->getId(),
        ];
    }

    protected function assignAgent(
        AppInterface $app,
        ZohoLead $zohoLead,
        Lead $lead,
        Companies $company,
        array &$zohoData
    ): void {
        $memberNumber = $zohoLead->getMemberNumber();
        $zohoService = new ZohoService($app, $company);

        try {
            $agent = $zohoService->getAgentByMemberNumber($memberNumber);
        } catch(Throwable $e) {
            $agent = null;
        }

        try {
            $agentInfo = Agent::getByMemberNumber($memberNumber, $company);
        } catch(Throwable $e) {
            $agentInfo = null;
        }

        if ($agent && $agent->count()) {
            $agent = $agent->first();
            $zohoData['Owner'] = (int) $agent->Owner['id'];
            if ($agent->Sponsor) {
                $zohoData['Sponsor'] = (string) $agent->Sponsor;
            }

            if ($agentInfo) {
                $lead->users_id = $agentInfo->users_id;
                $lead->saveOrFail();
            }

            if ($agentInfo && $agentInfo->get('over_write_owner')) {
                $zohoData['Owner'] = (int) $agentInfo->get('over_write_owner');
            }
        } elseif ($agentInfo) {
            $zohoData['Owner'] = $agentInfo->owner_linked_source_id;
            $data['Lead_Source'] = $agentInfo->name;

            if ($agentInfo->user && $agentInfo->user->get('sponsor')) {
                $zohoData['Sponsor'] = (string) $agent->user->get('sponsor');
            }
        }
    }

    protected function uploadAttachments(ZohoLeadModule $zohoLead, Lead $lead): void
    {
        if (! $lead->files()->count()) {
            return;
        }

        $syncFiles = $lead->get(CustomFieldEnum::ZOHO_LEAD_SYNC_FILES->value) ?? [];

        foreach ($lead->files()->get() as $file) {
            if (isset($syncFiles[$file->id])) {
                continue;
            }

            $fileContent = file_get_contents($file->url);

            $zohoLead->uploadAttachment(
                (string) $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value),
                $file->name,
                $fileContent
            );

            $syncFiles[$file->id] = $file->id;
        }

        $lead->set(
            CustomFieldEnum::ZOHO_LEAD_SYNC_FILES->value,
            $syncFiles
        );
    }
}
