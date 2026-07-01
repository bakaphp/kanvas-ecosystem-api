<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Webhooks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Actions\PullLeadAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Enums\TransactionCodeEnum;
use Kanvas\Connectors\Reynolds\Services\XmlParser;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Throwable;

#[WorkflowAction]
class ProcessReynoldsWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(array $params = []): array
    {
        $rawXml = (string) ($this->webhookRequest->raw_payload ?? '');
        if ($rawXml === '') {
            return ['status' => 'ignored', 'reason' => 'Empty payload'];
        }

        $payload = XmlParser::extractPayloadFromEnvelope($rawXml);
        $sender = $payload['ApplicationArea']['Sender'] ?? [];

        $task = TransactionCodeEnum::tryFrom((string) ($sender['Task'] ?? ''));
        if ($task === null) {
            Log::info('Reynolds webhook ignored: unknown Task', ['sender' => $sender]);

            return ['status' => 'ignored', 'reason' => 'Unknown Task code'];
        }

        // Reynolds publishes every dealer event to a single global URL — the
        // tenant identity lives inside the envelope's Sender block, not in the
        // receiver row's companies_id. ReynoldsHandler::setup() precomputes a
        // (Dealer|Store|Area) composite key into REYNOLDS_DEALER_LOCATION_KEY
        // so we resolve the Company with a single companies_settings lookup.
        $locationKey = ConfigurationEnum::buildDealerLocationKey(
            (string) ($sender['DealerNumber'] ?? ''),
            (string) ($sender['StoreNumber'] ?? ''),
            (string) ($sender['AreaNumber'] ?? ''),
        );

        $companyId = DB::table('companies_settings')
            ->where('name', ConfigurationEnum::REYNOLDS_DEALER_LOCATION_KEY->value)
            ->where('value', $locationKey)
            ->where(function ($q) {
                $q->where('is_deleted', 0)->orWhereNull('is_deleted');
            })
            ->value('companies_id');

        $company = $companyId ? Companies::find($companyId) : null;

        if ($company === null) {
            Log::warning('Reynolds webhook ignored: no matching company', ['sender' => $sender]);

            return ['status' => 'ignored', 'reason' => 'No matching company for Dealer/Store/Area'];
        }

        $defaultBranch = $company->defaultBranch;
        if ($defaultBranch !== null) {
            $this->overwriteAppServiceLocation($defaultBranch);
        }

        $user = $this->resolveUser($company);
        $record = $payload['Record'] ?? [];

        try {
            return match ($task) {
                TransactionCodeEnum::OUTBOUND_SALES_LEAD,
                TransactionCodeEnum::INSERT_SALES_LEAD,
                TransactionCodeEnum::UPDATE_SALES_LEAD,
                TransactionCodeEnum::LEAD_UPDATE => $this->upsertLead($company, $user, $record, $task),
                TransactionCodeEnum::DISPOSITION => $this->applyDisposition($company, $record, $task),
                TransactionCodeEnum::COMPLETED_ACTIVITY => $this->logActivity($company, $record, $task),
            };
        } catch (Throwable $e) {
            Log::error('Reynolds webhook processing failed', [
                'task' => $task->value,
                'company_id' => $company->getId(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function upsertLead(Companies $company, Users $user, array $record, TransactionCodeEnum $task): array
    {
        $lead = new PullLeadAction($this->receiver->app, $company, $user)->execute($record);

        return [
            'status' => 'success',
            'task' => $task->value,
            'lead_id' => $lead->getId(),
            'prospect_id' => $lead->get(CustomFieldEnum::PROSPECT_ID->value),
        ];
    }

    private function applyDisposition(Companies $company, array $record, TransactionCodeEnum $task): array
    {
        $prospectId = (string) ($record['Prospect']['ProspectId'] ?? '');
        $newStatusName = (string) ($record['Prospect']['ProspectStatusType'] ?? '');

        if ($prospectId === '' || $newStatusName === '') {
            return ['status' => 'ignored', 'task' => $task->value, 'reason' => 'Missing ProspectId or ProspectStatusType'];
        }

        $lead = Lead::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($company)
            ->notDeleted()
            ->whereHas(
                'customFields',
                fn ($q) => $q->where('name', CustomFieldEnum::PROSPECT_ID->value)
                    ->where('value', $prospectId)
            )
            ->first();

        if ($lead === null) {
            return ['status' => 'ignored', 'task' => $task->value, 'reason' => 'Lead not found for ProspectId ' . $prospectId];
        }

        $status = LeadStatus::query()
            ->fromApp($this->receiver->app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('name', $newStatusName)
            ->first();

        if ($status === null) {
            return ['status' => 'ignored', 'task' => $task->value, 'reason' => 'LeadStatus not seeded: ' . $newStatusName];
        }

        $lead->leads_status_id = $status->getId();
        $lead->saveOrFail();

        return [
            'status' => 'success',
            'task' => $task->value,
            'lead_id' => $lead->getId(),
            'new_status' => $newStatusName,
        ];
    }

    private function logActivity(Companies $company, array $record, TransactionCodeEnum $task): array
    {
        // No real ACT sample from R&R yet — log the inbound for analysis and avoid
        // a destructive write until we know what the Record shape carries.
        Log::info('Reynolds inbound ACT received (not yet processed)', [
            'company_id' => $company->getId(),
            'record_keys' => array_keys($record),
        ]);

        return ['status' => 'queued', 'task' => $task->value, 'reason' => 'ACT handler not yet implemented'];
    }

    private function resolveUser(Companies $company): Users
    {
        $aiUser = $company->getAiAgentUser();
        if ($aiUser instanceof Users) {
            return $aiUser;
        }

        /** @var Users|null $user */
        $user = $company->defaultBranch?->users()->first();

        return $user ?? Users::find(1);
    }
}
