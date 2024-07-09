<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
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
    use KanvasJobsTrait;
    public $tries = 10;

    /**
     * @param Lead $lead
     */
    public function execute(Model $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $zohoLead = ZohoLead::fromLead($lead);
        $zohoData = $zohoLead->toArray();
        $company = Companies::getById($lead->companies_id);
        $usesAgentsModule = $company->get(CustomFieldEnum::ZOHO_HAS_AGENTS_MODULE->value);

        $zohoCrm = Client::getInstance($app, $company);

        if ($usesAgentsModule) {
            $this->assignAgent($app, $zohoLead, $lead, $company, $zohoData);
        }

        if (! $zohoLeadId = $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value)) {
            $zohoLead = $zohoCrm->leads->create($zohoData);
            $zohoLeadId = $zohoLead->getId();

            $zohoData['Lead_Status'] = 'New Lead';

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
        $memberNumber = (string) $zohoLead->getMemberNumber();

        if (empty($memberNumber) && $lead->user()->exists()) {
            $memberNumber = (string) $lead->user()->firstOrFail()->get('member_number_' . $company->getId());
        }

        if (! empty($memberNumber)) {
            $zohoMemberField = $company->get(CustomFieldEnum::ZOHO_MEMBER_FIELD->value) ?? 'Member_ID';
            $zohoData[$zohoMemberField] = $memberNumber;
        }

        $zohoService = new ZohoService($app, $company);

        try {
            $agent = $zohoService->getAgentByMemberNumber($memberNumber);
        } catch (Throwable $e) {
            $agent = null;
        }

        try {
            $agentInfo = Agent::getByMemberNumber($memberNumber, $company);
        } catch (Throwable $e) {
            $agentInfo = null;
        }

        $defaultLeadSource = $company->get(CustomFieldEnum::ZOHO_DEFAULT_LEAD_SOURCE->value);
        if (! empty($defaultLeadSource)) {
            $zohoData['Lead_Source'] = $lead->receiver ? $lead->receiver->name : $defaultLeadSource;
        }

        if (is_object($agent)) {
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
        } elseif ($agentInfo instanceof Agent) {
            $zohoData['Owner'] = (int) $agentInfo->owner_linked_source_id;
            if (empty($defaultLeadSource)) {
                $zohoData['Lead_Source'] = $agentInfo->name;
            }

            if ($agentInfo->user && ! empty($agentInfo->user->get('sponsor'))) {
                $zohoData['Sponsor'] = (string) $agentInfo->user->get('sponsor');
            }
        }

        if ($company->get(CustomFieldEnum::ZOHO_USE_AGENT_NAME->value) && ! empty($agentInfo->name)) {
            $zohoData['Agent_Name'] = $agentInfo->name;
        }

        //if value is 0 or empty, remove it
        if (empty($zohoData['Owner'])) {
            unset($zohoData['Owner']);
        }
    }

    protected function uploadAttachments(ZohoLeadModule $zohoLead, Lead $lead): void
    {
        $lead->load('files');
        if (! $lead->files()->count()) {
            return;
        }

        $syncFiles = $lead->get(CustomFieldEnum::ZOHO_LEAD_SYNC_FILES->value) ?? [];

        foreach ($lead->files()->get() as $file) {
            if (isset($syncFiles[$file->id])) {
                continue;
            }

            try {
                $fileContent = file_get_contents($file->url);

                $zohoLead->uploadAttachment(
                    (string) $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value),
                    $file->name,
                    $fileContent
                );

                $syncFiles[$file->id] = $file->id;
            } catch (Throwable $e) {
                //do nothing
            }
        }

        $lead->set(
            CustomFieldEnum::ZOHO_LEAD_SYNC_FILES->value,
            $syncFiles
        );
    }
}
