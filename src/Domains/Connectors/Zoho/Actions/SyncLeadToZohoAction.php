<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Actions;

use Baka\Contracts\AppInterface;
use Baka\Http\SafeUrlFetcher;
use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Actions\Concerns\TracksZohoSyncedFiles;
use Kanvas\Connectors\Zoho\Actions\Concerns\WritesZohoRecordWithCoercion;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoFieldMapService;
use Kanvas\Connectors\Zoho\ZohoService;
use Kanvas\Guild\Agents\Models\Agent;
use Kanvas\Guild\Leads\Models\Lead;
use Sentry\Laravel\Facade as Sentry;
use Throwable;
use Webleit\ZohoCrmApi\Exception\ApiError;
use Webleit\ZohoCrmApi\Modules\Records as ZohoRecordsModule;

class SyncLeadToZohoAction
{
    use TracksZohoSyncedFiles;
    use WritesZohoRecordWithCoercion;

    public function __construct(
        protected AppInterface $app,
        protected Lead $lead
    ) {
    }

    public function execute(): array
    {
        return DB::transaction(function () {
            $lead = Lead::where('id', $this->lead->id)->lockForUpdate()->first();
            $zohoLead = ZohoLead::fromLead($this->lead);
            $zohoData = $zohoLead->toArray();
            $company = $lead->company;
            $receiver = $lead->receiver;
            $leadRotation = $receiver ? $receiver->rotation : null;
            $usesAgentsModule = $company->get(CustomFieldEnum::ZOHO_HAS_AGENTS_MODULE->value);

            $leadModule = $this->leadModule($company);

            if (! $zohoLeadId = $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value)) {
                $defaultOwner = $company->get(CustomFieldEnum::DEFAULT_OWNER->value);

                if (! isset($zohoData['Owner']) && $lead->owner !== null && ! empty($defaultOwner)) {
                    $zohoData['Owner'] = (string) $defaultOwner;
                }

                if ($usesAgentsModule && ! $leadRotation) {
                    $this->assignAgent($this->app, $zohoLead, $lead, $company, $zohoData);
                } elseif ($leadRotation) {
                    /* $rotationOwner = $leadRotation->getAgent();
                    $lead->users_id = $rotationOwner ? $rotationOwner->getId() : (int) $receiver->agents_id;
                    $lead->leads_owner_id = $lead->users_id;
                    $lead->disableWorkflows();
                    $lead->saveOrFail();

                    // Reload the owner relationship to ensure it's up-to-date
                    $lead->load('owner'); */

                    $zohoData['Lead_Source'] = $receiver->name ?? 'Kanvas Api';
                    $zohoData['Owner'] = $lead->owner->get(CustomFieldEnum::ZOHO_USER_OWNER_ID->value) ?? $company->get(CustomFieldEnum::DEFAULT_OWNER->value);
                }

                if (! isset($zohoData['Lead_Status'])) {
                    $zohoData['Lead_Status'] = 'New Lead';
                }
                $organization = $lead->organization;
                if ($organization) {
                    $zohoData['Company'] = $organization->name;
                }

                try {
                    $zohoLead = $this->createRecord($leadModule, $zohoData);
                    $zohoLeadId = $zohoLead?->getId();

                    $lead->set(
                        CustomFieldEnum::ZOHO_LEAD_ID->value,
                        $zohoLeadId
                    );

                    $this->writeLeadIdLinks($leadModule, $lead, $zohoLeadId);
                } catch (ApiError $e) {
                    Sentry::withScope(function ($scope) use ($zohoData, $lead, $e) {
                        $scope->setContext('Lead Zoho Data', [
                            'zohoData' => $zohoData,
                            'leadId' => $lead->getId(),
                            'details' => $e->details(),
                            'message' => (string) $e->response()->getBody(),
                        ]);

                        report($e);
                    });
                }
            } else {
                $zohoLeadInfo = $leadModule->get((string) $zohoLeadId)->getData();
                if (! empty($zohoLeadInfo)) {
                    $zohoData = [
                        ...$zohoData,
                        ...ZohoFieldMapService::leadIdLinkFields($lead, (string) $zohoLeadId),
                    ];
                    $zohoLead = $this->updateRecord(
                        $leadModule,
                        (string) $zohoLeadId,
                        $zohoData
                    );
                } else {
                    $lead->close();
                }
            }

            $this->uploadAttachments($leadModule, $lead);

            return $zohoData;
        });
    }

    protected function leadModule(Companies $company): ZohoRecordsModule
    {
        return ZohoService::resolveLeadModule(Client::getInstance($this->app, $company), $company);
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
            $zohoData['Lead_Source'] = $defaultLeadSource; //$lead->receiver ? $lead->receiver->name : $defaultLeadSource;
        }

        if (is_object($agent)) {
            try {
                ///lead owner should match lead routing
                $leadRoutingEmailCleanUp = preg_replace('/[^a-zA-Z0-9@._-]/', '', $agent->Lead_Routing);
                $zohoData['Owner'] = $zohoService->getAgentByEmail($leadRoutingEmailCleanUp)->Owner['id'];
            } catch (Throwable $e) {
                //send fail notification and assign to default lead routing email
                $zohoData['Owner'] = (int) ($app->get(CustomFieldEnum::DEFAULT_OWNER->value) ?? $agent->Owner['id']);
            }

            if ($agent->Sponsor) {
                $zohoData['Sponsor'] = (string) $agent->Sponsor;
            }

            if ($agentInfo !== null && $agentInfo->users_id > 0) {
                $lead->users_id = $agentInfo->users_id;
                $lead->saveOrFail();
            }

            if ($agentInfo && $agentInfo->get('over_write_owner')) {
                $zohoData['Owner'] = (int) $agentInfo->get('over_write_owner');
            }
            if (empty($defaultLeadSource)) {
                $zohoData['Lead_Source'] = $agent->name ?? $agent->Name;
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

    /**
     * Second write on purpose: the Zoho id these links embed doesn't exist until the create returns.
     */
    protected function writeLeadIdLinks(ZohoRecordsModule $leadModule, Lead $lead, ?string $zohoLeadId): void
    {
        $links = ZohoFieldMapService::leadIdLinkFields($lead, $zohoLeadId);

        if ($links === []) {
            return;
        }

        $this->updateRecord($leadModule, (string) $zohoLeadId, $links);
    }

    protected function uploadAttachments(ZohoRecordsModule $zohoLead, Lead $lead): void
    {
        if (! ZohoLead::hasAttachments($lead)) {
            return;
        }

        $zohoLeadId = (string) $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value);
        $attachmentUrls = array_filter(array_map('trim', explode(',', (string) $lead->get('Attachments'))));

        foreach ($attachmentUrls as $attachment) {
            try {
                $fileContent = SafeUrlFetcher::fetch($attachment);
                $fileName = Str::fileNameFromUrl($attachment, 'attachment_' . uniqid());

                $zohoLead->uploadAttachment($zohoLeadId, $fileName, $fileContent);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $pushed = new PushFilesToZohoRecordAction($zohoLead, $zohoLeadId)->execute(
            $lead->files()->get(),
            $this->syncedFileIds($lead, CustomFieldEnum::ZOHO_LEAD_SYNC_FILES)
        );

        $this->rememberSyncedFiles($lead, CustomFieldEnum::ZOHO_LEAD_SYNC_FILES, $pushed['uploaded']);
    }
}
