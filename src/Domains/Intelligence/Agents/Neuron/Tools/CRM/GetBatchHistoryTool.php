<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Guild\Leads\Enums\LeadCommunicationChannelEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Batch history / send results for the manager: recent batch campaigns with their status and
 * sent/failed/skipped/opened counts, or the per-recipient result breakdown of a single campaign. Read-only,
 * company-scoped. Use for "show me my last batch", "how did the RAV4 blast go?", "what happened with
 * campaign 42?".
 */
#[AgentTool(name: 'Get Batch History', category: 'crm')]
class GetBatchHistoryTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'get_batch_history';

    protected ?string $description = 'Review batch outreach campaigns. With no arguments, lists recent campaigns with their '
        . 'channel, status, sent/failed/skipped/opened counts and email open rate. Pass campaign_id to get that '
        . 'campaign\'s detail plus a sample of recipients that failed or were skipped, with reasons, and a sample '
        . 'of who opened the email. Opens are only tracked for emails sent from an agent mailbox.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'campaign_id', type: PropertyType::INTEGER, description: 'A specific campaign id to inspect. Omit to list recent campaigns.', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'How many recent campaigns to list. Default 10, max 50.', required: false),
            ...$this->tenantCompanyProperty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $campaign_id = null, ?int $limit = null, ?int $tenant_company_id = null): array
    {
        if (($refusal = $this->narrowReadScope($tenant_company_id)) !== null) {
            return $refusal;
        }

        if ($campaign_id !== null && $campaign_id > 0) {
            return $this->campaignDetail($campaign_id);
        }

        $limit = max(1, min(50, $limit ?? 10));

        $campaigns = Campaign::query()
            ->fromApp($this->app)
            ->fromCompany($this->auditedReadScope())
            ->notDeleted()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'status' => 'success',
            'count' => $campaigns->count(),
            'campaigns' => $campaigns->map(fn (Campaign $c): array => $this->summary($c))->all(),
            ...$this->tenantScopeSummary(),
        ];
    }

    /**
     * Resolved in the read scope: a read-only detail of a row the list may have returned from another
     * company.
     *
     * @return array<string, mixed>
     */
    private function campaignDetail(int $campaignId): array
    {
        /** @var Campaign|null $campaign */
        $campaign = Campaign::query()
            ->where('id', $campaignId)
            ->fromApp($this->app)
            ->fromCompany($this->auditedReadScope())
            ->notDeleted()
            ->first();

        if ($campaign === null) {
            return [
                'status' => 'error',
                'message' => sprintf('Campaign #%d was not found in your companies. Do not retry with the same id.', $campaignId),
            ];
        }

        $problems = $campaign->recipients()
            ->whereIn('status', ['failed', 'skipped'])
            ->where('is_deleted', 0)
            ->limit(25)
            ->get()
            ->map(fn (CampaignRecipient $r): array => [
                'lead_id' => $r->leads_id,
                'status' => $r->status,
                'reason' => $r->reason,
            ])->all();

        $opened = $campaign->recipients()
            ->whereNotNull('opened_at')
            ->where('is_deleted', 0)
            ->orderByDesc('opened_at')
            ->limit(25)
            ->get()
            ->map(fn (CampaignRecipient $r): array => [
                'lead_id' => $r->leads_id,
                'people_id' => $r->peoples_id,
                'opened_at' => $r->opened_at?->toIso8601String(),
                'open_count' => $r->open_count,
            ])->all();

        return [
            'status' => 'success',
            'campaign' => $this->summary($campaign),
            'problem_recipients_sample' => $problems,
            'opened_recipients_sample' => $opened,
        ];
    }

    /**
     * Null rather than 0 when opens can't be tracked at all — an SMS batch reporting "0% opened" would
     * read as a failed campaign.
     */
    private function openRate(Campaign $campaign): ?float
    {
        if ($campaign->channel !== LeadCommunicationChannelEnum::EMAIL->value || $campaign->sent_count === 0) {
            return null;
        }

        return round($campaign->opened_count / $campaign->sent_count, 4);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Campaign $campaign): array
    {
        return [
            'campaign_id' => $campaign->getId(),
            'channel' => $campaign->channel,
            'status' => $campaign->status,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'total_recipients' => $campaign->total_recipients,
            'sent' => $campaign->sent_count,
            'failed' => $campaign->failed_count,
            'skipped' => $campaign->skipped_count,
            'opened' => $campaign->opened_count,
            'open_rate' => $this->openRate($campaign),
            'created_at' => $campaign->created_at?->toIso8601String(),
            ...$this->tenantLabel($campaign),
        ];
    }
}
