<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Services\FindLeadsByTraitsService;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Finds leads that share traits and returns a vetted batch-outreach recipient list — the first step
 * of the manager batch-messaging flow. Read-only: it NEVER sends anything. It resolves who is
 * eligible to be contacted (dedups people, drops do-not-contact and undeliverable/opted-out) and
 * reports why each excluded lead was dropped, so the manager can review before confirming a send.
 *
 * Company-wide, internal-teammate capability (managers), not the customer-facing surface. v1 filters
 * the reliable structured traits only (source, status, stage, salesperson, rooftop, dates,
 * staleness and generic product/variant interests.
 */
#[AgentTool(name: 'Find Leads By Traits', category: 'crm')]
class FindLeadsByTraitsTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct(
        private readonly FindLeadsByTraitsService $finder = new FindLeadsByTraitsService(),
    ) {
        parent::__construct(
            name: 'find_leads_by_traits',
            description: 'Find a group of leads that share traits and build a reviewable batch-outreach recipient '
                . 'list. Use for "find all leads interested in a RAV4 who have not responded in 7 days", "find customers '
                . 'looking for used trucks under $30k", "find all leads interested in a 2025 Tacoma", "RAV4 leads who '
                . 'can receive SMS", "internet leads from the last 14 days", and "leads assigned to Alex with no contact '
                . 'in 3 days". Product interests are generic: resolve matching inventory variants by text, searchable '
                . 'attributes and price, then require an exact lead-to-variant interest relation. Filters also include '
                . 'engagement progress: use action "trade-in" or "credit-app" with completion "incomplete" for '
                . '"trade-in not submitted" and "incomplete credit applications". '
                . 'Engagement results are authoritative: never substitute lead titles, messages, RAG snippets or '
                . 'keyword matches when this structured filter returns zero. '
                . 'Communication filters use the latest structured message sender: awaiting_team_response means the '
                . 'last communication is from the customer; never_replied means outbound exists but no customer message exists. '
                . 'status, source, stage, salesperson, rooftop/store, created dates and days-since-last-update. Returns the eligible '
                . 'recipients plus the leads that were excluded (opted-out, do-not-contact, no contact info, duplicate) '
                . 'with reasons. THIS DOES NOT SEND ANYTHING — it is the review step. After the manager confirms the '
                . 'list and the message, call send_batch_message with the eligible lead_ids.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'status', type: PropertyType::STRING, description: 'open (default), closed, or all.', required: false),
            new ToolProperty(name: 'source', type: PropertyType::STRING, description: 'Lead source name (partial match), e.g. "internet", "walk-in".', required: false),
            new ToolProperty(name: 'stage', type: PropertyType::STRING, description: 'Pipeline stage name (partial match).', required: false),
            new ToolProperty(name: 'salesperson', type: PropertyType::STRING, description: 'Assigned salesperson by partial name or email.', required: false),
            new ToolProperty(name: 'rooftop', type: PropertyType::STRING, description: 'Store / rooftop (branch) name, partial match.', required: false),
            new ToolProperty(name: 'created_after', type: PropertyType::STRING, description: 'Only leads created on/after this date (YYYY-MM-DD).', required: false),
            new ToolProperty(name: 'created_before', type: PropertyType::STRING, description: 'Only leads created on/before this date (YYYY-MM-DD).', required: false),
            new ToolProperty(name: 'no_update_since_days', type: PropertyType::INTEGER, description: 'Only leads with no record activity in at least this many days (proxy for "no response since").', required: false),
            new ToolProperty(name: 'variant_query', type: PropertyType::STRING, description: 'Product or variant text, SKU, EAN or barcode, e.g. "RAV4", "2025 Tacoma", or "truck".', required: false),
            new ArrayProperty(
                name: 'variant_attributes',
                description: 'Exact searchable variant attributes written as name:value, e.g. ["condition:used", "body_type:truck"]. Generic across apps; do not assume automotive fields.',
                required: false,
                items: new ToolProperty(name: 'attribute', type: PropertyType::STRING, description: 'One searchable attribute as name:value.'),
            ),
            new ToolProperty(name: 'minimum_variant_price', type: PropertyType::NUMBER, description: 'Minimum recorded price when the lead expressed interest.', required: false),
            new ToolProperty(name: 'maximum_variant_price', type: PropertyType::NUMBER, description: 'Maximum recorded price when the lead expressed interest, e.g. 30000.', required: false),
            new ToolProperty(name: 'engagement_action', type: PropertyType::STRING, description: 'Engagement action or alias, e.g. trade-in, add-trade, credit-app, get-docs, or esign-docs.', required: false),
            new ToolProperty(name: 'engagement_completion', type: PropertyType::STRING, description: 'Engagement state: started, incomplete, submitted, or missing. Incomplete means the latest state for an engagement entity is not submitted.', required: false),
            new ToolProperty(name: 'communication_state', type: PropertyType::STRING, description: 'Communication state: awaiting_team_response, responded, never_replied, or no_messages.', required: false),
            new ToolProperty(name: 'customer_waiting_since_days', type: PropertyType::INTEGER, description: 'For awaiting_team_response, require the latest inbound customer message to be at least this many days old.', required: false),
            new ToolProperty(name: 'channel', type: PropertyType::STRING, description: 'Channel the batch will use: sms (default) or email. Determines which contacts must be deliverable.', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max candidate leads to evaluate, most recently updated first. Default 100, max 500.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $status = null,
        ?string $source = null,
        ?string $stage = null,
        ?string $salesperson = null,
        ?string $rooftop = null,
        ?string $created_after = null,
        ?string $created_before = null,
        ?int $no_update_since_days = null,
        ?string $variant_query = null,
        ?array $variant_attributes = null,
        ?float $minimum_variant_price = null,
        ?float $maximum_variant_price = null,
        ?string $engagement_action = null,
        ?string $engagement_completion = null,
        ?string $communication_state = null,
        ?int $customer_waiting_since_days = null,
        ?string $channel = null,
        ?int $limit = null,
    ): array {
        return $this->finder->execute($this->app, $this->company, compact(
            'status', 'source', 'stage', 'salesperson', 'rooftop', 'created_after', 'created_before',
            'no_update_since_days', 'variant_query', 'variant_attributes', 'minimum_variant_price',
            'maximum_variant_price', 'engagement_action', 'engagement_completion', 'channel', 'limit',
            'communication_state', 'customer_waiting_since_days',
        ));
    }
}
