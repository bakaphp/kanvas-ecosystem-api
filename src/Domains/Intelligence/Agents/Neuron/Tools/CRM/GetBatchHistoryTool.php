<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Campaigns\Models\Campaign;
use Kanvas\Guild\Campaigns\Models\CampaignRecipient;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Batch history / send results for the manager: recent batch campaigns with their status and
 * sent/failed/skipped counts, or the per-recipient result breakdown of a single campaign. Read-only,
 * company-scoped. Use for "show me my last batch", "how did the RAV4 blast go?", "what happened with
 * campaign 42?".
 */
#[AgentTool(name: 'Get Batch History', category: 'crm')]
class GetBatchHistoryTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'get_batch_history',
            description: 'Review batch outreach campaigns. With no arguments, lists recent campaigns with their '
                . 'channel, status, and sent/failed/skipped counts. Pass campaign_id to get that campaign\'s detail '
                . 'plus a sample of recipients that failed or were skipped, with reasons.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'campaign_id', type: PropertyType::INTEGER, description: 'A specific campaign id to inspect. Omit to list recent campaigns.', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'How many recent campaigns to list. Default 10, max 50.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $campaign_id = null, ?int $limit = null): array
    {
        if ($campaign_id !== null && $campaign_id > 0) {
            return $this->campaignDetail($campaign_id);
        }

        $limit = max(1, min(50, $limit ?? 10));

        $campaigns = Campaign::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'status' => 'success',
            'count' => $campaigns->count(),
            'campaigns' => $campaigns->map(fn (Campaign $c): array => $this->summary($c))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignDetail(int $campaignId): array
    {
        /** @var Campaign $campaign */
        $campaign = Campaign::getByIdFromCompanyApp($campaignId, $this->company, $this->app);

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

        return [
            'status' => 'success',
            'campaign' => $this->summary($campaign),
            'problem_recipients_sample' => $problems,
        ];
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
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }
}
