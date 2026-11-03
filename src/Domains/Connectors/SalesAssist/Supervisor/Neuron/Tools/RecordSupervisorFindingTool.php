<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Supervisor\Neuron\Tools;

use Illuminate\Support\Str;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorConfigurationAuditService as Audit;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorFindingLedger;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The one write this agent has, and it only reaches the ledger. Evidence is checked against the
 * dealer's own messages: a finding the model cannot point at does not get recorded.
 */
class RecordSupervisorFindingTool extends Tool
{
    use HasKanvasContext;

    private const array SEVERITIES = [Audit::SEVERITY_CRITICAL, Audit::SEVERITY_HIGH, Audit::SEVERITY_MEDIUM];

    protected string $name = 'record_supervisor_finding';

    protected ?string $description = 'Records a conversation finding in the ledger for a human to review. Requires the '
        . 'lead, the message ids that prove it, and the policy document it breaks; leave policy_source empty '
        . 'when no document covers the case, and it is recorded as having no policy. Sends nothing to anyone '
        . 'and changes no record.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty('lead_id', PropertyType::INTEGER, 'The lead the conversation belongs to.', true),
            new ToolProperty('category', PropertyType::STRING, 'Short kebab-case type, e.g. "opt-out-ignored".', true),
            new ToolProperty('severity', PropertyType::STRING, 'One of: critical, high, medium.', true),
            new ToolProperty('summary', PropertyType::STRING, 'One sentence: what is wrong and why it matters.', true),
            new ToolProperty('message_ids', PropertyType::STRING, 'Comma-separated ids of the messages that prove it.', true),
            new ToolProperty('policy_source', PropertyType::STRING, 'Name of the policy document, as search_knowledge labelled it.', false),
        ];
    }

    /**
     * @return array{status: string, message: string}
     */
    public function __invoke(
        int $lead_id,
        string $category,
        string $severity,
        string $summary,
        string $message_ids,
        ?string $policy_source = null,
    ): array {
        if (! $this->hasTenantContext()) {
            return ['status' => 'error', 'message' => 'No company context.'];
        }

        if (! in_array($severity, self::SEVERITIES, true)) {
            return ['status' => 'error', 'message' => 'severity must be critical, high or medium.'];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $message_ids)))));
        sort($ids);

        if ($ids === [] || ! $this->messagesBelongToLead($lead_id, $ids)) {
            return ['status' => 'error', 'message' => 'Every message id must be a message of this lead. Nothing was recorded.'];
        }

        $category = Str::slug($category);
        $ledger = new SupervisorFindingLedger($this->app, $this->company);
        $fingerprint = SupervisorFindingLedger::fingerprint(
            SupervisorFindingLedger::ORIGIN_CONVERSATION,
            (string) $lead_id,
            $category,
        );

        if (isset($ledger->open()[$fingerprint])) {
            return ['status' => 'success', 'message' => 'Already open for this lead; not recorded again.'];
        }

        $ledger->record(SupervisorFindingLedger::ORIGIN_CONVERSATION, $fingerprint, [
            'code' => "conversation.{$category}",
            'severity' => $severity,
            'summary' => $summary,
            'policy_status' => blank($policy_source) ? 'no_policy' : 'policy_cited',
            'evidence' => ['lead_id' => $lead_id, 'message_ids' => $ids, 'policy_source' => $policy_source],
        ]);

        return ['status' => 'success', 'message' => 'Finding recorded.'];
    }

    /**
     * @param list<int> $ids
     */
    private function messagesBelongToLead(int $leadId, array $ids): bool
    {
        return AppModuleMessage::query()
            ->where('system_modules', Lead::class)
            ->where('entity_id', $leadId)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereIn('message_id', $ids)
            ->distinct()
            ->count('message_id') === count($ids);
    }
}
