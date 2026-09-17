<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Jobs\SummarizeLeadConversationJob;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadConversationSummaryRepository;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\LeadConversationTranscriptService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Social\Messages\Models\Message;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Past Opportunities', category: 'crm')]
class PastOpportunitiesTool extends Tool
{
    use ResolvesLeadForTool;

    private const int LIMIT = 10;
    private const int RECENT_MESSAGES = 3;

    public function __construct()
    {
        parent::__construct(
            name: 'get_past_opportunities',
            description: 'Get the previous opportunities (earlier leads) of the person behind this lead: title, '
                . 'description, status, stage, whether it is still open, vehicle of interest, owner, date, and a '
                . 'summary of what was discussed (or the last few messages when no summary exists yet). '
                . 'Only call it when get_lead_ref says customer_type is "returning" and the past history matters '
                . 'for your reply (e.g. they mention a previous visit, purchase, or vehicle).',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }

        $pastLeads = LeadsRepository::getPastOpportunities($result)
            ->with(['status', 'stage', 'owner', 'company'])
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get();

        $summaries = LeadConversationSummaryRepository::latestForLeads($result->app, $pastLeads->modelKeys());

        return [
            'status' => 'success',
            'past_opportunities' => $pastLeads->map(fn (Lead $pastLead): array => [
                'lead_id' => $pastLead->getId(),
                'title' => $pastLead->title,
                'description' => $pastLead->description,
                // `status` is also a column, and the column shadows the eager-loaded relation.
                'status' => $pastLead->getRelationValue('status')?->name,
                'stage' => $pastLead->stage?->name,
                'is_open' => $pastLead->hasOpenLeadStatus(),
                'vehicle_interest' => $pastLead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value),
                'owner' => $pastLead->owner
                    ? trim($pastLead->owner->firstname . ' ' . $pastLead->owner->lastname)
                    : null,
                'created_at' => $pastLead->created_at?->format('Y-m-d'),
                ...$this->conversation($pastLead, $summaries[$pastLead->getId()] ?? null),
            ])->values()->all(),
        ];
    }

    /**
     * A missing summary never blocks the reply: hand back the tail of the conversation now and
     * queue the summary so the next lookup is a plain read.
     *
     * @return array{conversation_summary: string|null, recent_messages?: list<string>}
     */
    private function conversation(Lead $pastLead, ?Message $summary): array
    {
        if ($summary !== null) {
            return ['conversation_summary' => $summary->getMessage()['content'] ?? null];
        }

        SummarizeLeadConversationJob::dispatchIfEligible($pastLead);

        return [
            'conversation_summary' => null,
            'recent_messages' => array_slice(
                LeadConversationTranscriptService::lines($pastLead),
                -self::RECENT_MESSAGES
            ),
        ];
    }
}
