<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Actions\CreateDealAction;
use Kanvas\Guild\Deals\Actions\UpdateDealAction;
use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Throwable;
use Webleit\ZohoCrmApi\ZohoCrm;

#[WorkflowAction(
    name: 'Zoho Deal Webhook',
    description: 'Receiver for Zoho deals: creates or updates the matching Kanvas deal, links it to its lead '
        . 'and person, and pulls down any attachments. Inbound one-way, and deliberately does NOT '
        . 're-run workflows on the deal it writes, so a Zoho change cannot bounce straight back out.',
    integration: IntegrationsEnum::ZOHO,
)]
class ProcessZohoDealWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = is_array($this->webhookRequest->payload) ? $this->webhookRequest->payload : [];
        $zohoDealId = $payload['DealID'] ?? $payload['id'] ?? null;

        if ($zohoDealId === null || $zohoDealId === '') {
            return ['message' => 'Zoho Deal ID not found in payload'];
        }

        $zohoDealId = (string) $zohoDealId;
        /** @var Apps $app */
        $app = $this->receiver->app;
        /** @var Companies $company */
        $company = $this->receiver->company;
        /** @var Users $user */
        $user = $this->receiver->user;

        $zohoCrm = Client::getInstance($app, $company);
        $zohoDealInfo = $zohoCrm->deals->get($zohoDealId)->getData();

        if (empty($zohoDealInfo)) {
            return [
                'message' => 'Zoho Deal not found',
                'zoho_deal_id' => $zohoDealId,
            ];
        }

        $lead = $this->findRelatedLead($zohoDealInfo, $company);
        $people = $this->findPeople($zohoDealInfo, $lead, $company, $app);

        /** @var Deal|null $existing */
        $existing = Deal::getByCustomField(
            CustomFieldEnum::ZOHO_DEAL_ID->value,
            $zohoDealId,
            $company
        );

        $dealData = new DealData(
            app: $app,
            company: $company,
            user: $user,
            title: (string) ($zohoDealInfo['Deal_Name'] ?? $zohoDealInfo['Name'] ?? ('Zoho Deal ' . $zohoDealId)),
            description: isset($zohoDealInfo['Description']) ? (string) $zohoDealInfo['Description'] : null,
            lead: $lead,
            people: $people,
        );

        $deal = $existing !== null
            ? new UpdateDealAction($existing, $dealData, runWorkflow: false)->execute()
            : new CreateDealAction($dealData, runWorkflow: false)->execute();

        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, $zohoDealId);
        $deal->set(CustomFieldEnum::ZOHO_DEAL_RAW->value, $zohoDealInfo);

        $this->mapCustomFields($deal, $zohoDealInfo, $company);
        $this->syncAttachments($deal, $zohoCrm, $zohoDealId, $app, $user, $company);

        $deal->fireWorkflow(
            $existing !== null ? WorkflowEnum::UPDATED->value : WorkflowEnum::CREATED->value,
            true,
            ['zoho_payload' => $zohoDealInfo]
        );

        return [
            'message' => 'Zoho Deal synced to Kanvas',
            'deal_id' => $deal->getId(),
            'deal_uuid' => $deal->uuid,
            'zoho_deal_id' => $zohoDealId,
            'created' => $existing === null,
        ];
    }

    protected function findRelatedLead(array $zohoDealInfo, Companies $company): ?Lead
    {
        $zohoLeadId = $zohoDealInfo['ZCRM_Lead_ID'] ?? null;
        if (! is_string($zohoLeadId) && ! is_int($zohoLeadId)) {
            return null;
        }
        $zohoLeadId = (string) $zohoLeadId;
        if ($zohoLeadId === '') {
            return null;
        }

        /** @var Lead|null $lead */
        $lead = Lead::getByCustomField(
            CustomFieldEnum::ZOHO_LEAD_ID->value,
            $zohoLeadId,
            $company
        );

        return $lead;
    }

    protected function findPeople(array $zohoDealInfo, ?Lead $lead, Companies $company, Apps $app): ?People
    {
        if ($lead !== null && $lead->people !== null) {
            return $lead->people;
        }

        $email = $zohoDealInfo['Email'] ?? $zohoDealInfo['Primary_Email'] ?? null;
        if (! is_string($email) || $email === '') {
            return null;
        }

        /** @var People|null $people */
        $people = People::fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->whereHas(
                'contacts',
                fn (Builder $q) => $q->where('value', $email)
            )
            ->first();

        return $people;
    }

    protected function mapCustomFields(Deal $deal, array $zohoDealInfo, Companies $company): void
    {
        $map = $company->get(CustomFieldEnum::ZOHO_DEAL_FIELDS_MAP->value);
        if (! is_array($map) || $map === []) {
            $map = $this->defaultFieldMap();
        }

        foreach ($map as $zohoKey => $kanvasKey) {
            if (! is_string($kanvasKey) || $kanvasKey === '') {
                continue;
            }
            if (! is_string($zohoKey) || ! array_key_exists($zohoKey, $zohoDealInfo)) {
                continue;
            }
            $deal->set($kanvasKey, $zohoDealInfo[$zohoKey]);
        }
    }

    protected function defaultFieldMap(): array
    {
        return [
            'Business_Name' => 'business_legal_name',
            'DBA' => 'business_dba',
            'EIN' => 'business_ein',
            'Monthly_Business_Revenue' => 'business_stated_monthly_revenue',
            'Annual_Revenue' => 'funding_estimated_annual_revenue',
            'Amount_Requested' => 'funding_amount_requested',
            'Business_Start_Date' => 'business_start_date',
            'Employer_Address' => 'business_address_line',
            'City' => 'business_city',
            'State' => 'business_state',
            'Zip_Code' => 'business_zip',
        ];
    }

    protected function syncAttachments(
        Deal $deal,
        ZohoCrm $zohoCrm,
        string $zohoDealId,
        Apps $app,
        Users $user,
        Companies $company
    ): void {
        try {
            $attachmentsRaw = $zohoCrm->deals->getRelatedRecords($zohoDealId, 'Attachments');
        } catch (Throwable) {
            return;
        }

        if (! is_array($attachmentsRaw) || $attachmentsRaw === []) {
            return;
        }
        // Zoho wraps records in {"data": [...], "info": {...}} — unwrap the data list
        $attachments = isset($attachmentsRaw['data']) && is_array($attachmentsRaw['data'])
            ? $attachmentsRaw['data']
            : $attachmentsRaw;
        if ($attachments === []) {
            return;
        }

        $existingNames = [];
        foreach ($deal->getFiles() as $existing) {
            $existingNames[] = (string) ($existing->name ?? '');
        }

        $filesystemService = new FilesystemServices($app, $company);

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $attachmentId = $attachment['id'] ?? null;
            $fileName = $attachment['File_Name'] ?? $attachment['name'] ?? null;

            if (! is_string($attachmentId) || $attachmentId === '') {
                continue;
            }
            if (! is_string($fileName) || $fileName === '') {
                continue;
            }
            if (in_array($fileName, $existingNames, true)) {
                continue;
            }

            $this->downloadAndAttach(
                $zohoCrm,
                $zohoDealId,
                $attachmentId,
                $fileName,
                $deal,
                $filesystemService,
                $user
            );
        }
    }

    protected function downloadAndAttach(
        ZohoCrm $zohoCrm,
        string $zohoDealId,
        string $attachmentId,
        string $fileName,
        Deal $deal,
        FilesystemServices $filesystemService,
        Users $user
    ): void {
        $tmpPath = tempnam(sys_get_temp_dir(), 'zoho-attach-');
        if ($tmpPath === false) {
            return;
        }

        $resource = fopen($tmpPath, 'w+');
        if ($resource === false) {
            @unlink($tmpPath);

            return;
        }

        try {
            $zohoCrm->deals->downloadAttachment($zohoDealId, $attachmentId, $resource);
        } catch (Throwable) {
            /** @psalm-suppress RedundantCondition — Zoho SDK may close the stream itself */
            if (is_resource($resource)) {
                fclose($resource);
            }
            @unlink($tmpPath);

            return;
        }
        /** @psalm-suppress RedundantCondition — Zoho SDK may close the stream itself */
        if (is_resource($resource)) {
            fclose($resource);
        }

        try {
            $mime = (string) (mime_content_type($tmpPath) ?: 'application/octet-stream');
            $uploadedFile = new UploadedFile($tmpPath, $fileName, $mime, null, true);
            $filesystem = $filesystemService->upload($uploadedFile, $user);
            new AttachFilesystemAction($filesystem, $deal)->execute($fileName);
        } catch (Throwable) {
            // swallow — continue with next attachment
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
