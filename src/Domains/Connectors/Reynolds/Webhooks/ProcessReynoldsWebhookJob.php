<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Webhooks;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Reynolds\Actions\PullLeadAction;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Enums\TransactionCodeEnum;
use Kanvas\Connectors\Reynolds\Services\TenantResolver;
use Kanvas\Connectors\Reynolds\Services\XmlParser;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Users\Models\Users;
use Throwable;

class ProcessReynoldsWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Apps $app,
        public readonly string $rawXml,
    ) {
    }

    public function handle(): array
    {
        $this->overwriteAppService($this->app);

        $payload = XmlParser::extractPayloadFromEnvelope($this->rawXml);
        $sender = $payload['ApplicationArea']['Sender'] ?? [];

        $task = TransactionCodeEnum::tryFrom((string) ($sender['Task'] ?? ''));
        if ($task === null) {
            Log::info('Reynolds webhook ignored: unknown Task', ['sender' => $sender]);

            return ['status' => 'ignored', 'reason' => 'Unknown Task code'];
        }

        $company = TenantResolver::fromSender(
            dealerNumber: (string) ($sender['DealerNumber'] ?? ''),
            storeNumber: (string) ($sender['StoreNumber'] ?? ''),
            areaNumber: (string) ($sender['AreaNumber'] ?? ''),
            app: $this->app,
        );

        if ($company === null) {
            Log::warning('Reynolds webhook ignored: no matching company', ['sender' => $sender]);

            return ['status' => 'ignored', 'reason' => 'No matching company for Dealer/Store/Area'];
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
        $lead = new PullLeadAction($this->app, $company, $user)->execute($record);

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
            ->fromApp($this->app)
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
            ->fromApp($this->app)
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
