<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Reads the `scribe.document.ingested` ledger events the accounting inbox writes, one per document,
 * so the agent can answer "what came in and what became of it" without the inbox telling it each time.
 */
#[AgentTool(name: 'Recent Inbox Arrivals', category: 'accounting')]
class RecentInboxArrivalsTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'recent_inbox_arrivals';

    protected ?string $description = 'Lists the documents that reached the company accounting inbox and what became of each one: '
        . 'bill_created, expense_created, bank_statement_created, needs_review (a bill left DRAFT, with the reason), duplicate, '
        . 'rejected, failed, '
        . 'or routed_to_ar (a remittance, customer PO or credit request handed to the AR agent). Use it for "did the '
        . 'Claro invoice arrive", "what came in today", or to find the bills waiting on you. Read-only.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'days',
                type: PropertyType::INTEGER,
                description: 'How many days back to look. Default 7, max 90.',
                required: false,
            ),
            new ToolProperty(
                name: 'outcome',
                type: PropertyType::STRING,
                description: 'Only this outcome: ' . implode(', ', ProcessAccountingPdfAction::OUTCOMES) . '. Omit for all.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max documents to return. Default 25, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $days = null, ?string $outcome = null, ?int $limit = null): array
    {
        $days = max(1, min(90, $days ?? 7));
        $limit = max(1, min(100, $limit ?? 25));
        $outcome = strtolower(trim((string) $outcome));

        if ($outcome !== '' && ! in_array($outcome, ProcessAccountingPdfAction::OUTCOMES, true)) {
            return [
                'count' => 0,
                'arrivals' => [],
                'error' => "Unknown outcome \"{$outcome}\". Use one of: " . implode(', ', ProcessAccountingPdfAction::OUTCOMES) . ', or omit it.',
            ];
        }

        $events = Event::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('event_type', ProcessAccountingPdfAction::INGESTED_EVENT)
            ->where('occurred_at', '>=', Carbon::now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($outcome === '' ? $limit : $limit * 5)
            ->get()
            ->filter(fn (Event $event): bool => $outcome === '' || ($event->payload['outcome'] ?? null) === $outcome)
            ->take($limit)
            ->values();

        $arrivals = $events->map(fn (Event $event): array => [
            'received_at' => $event->occurred_at?->toIso8601String(),
            'outcome' => $event->payload['outcome'] ?? null,
            'document_type' => $event->payload['document_type'] ?? null,
            'from_email' => $event->payload['from_email'] ?? null,
            'subject' => $event->payload['subject'] ?? null,
            'reason' => $event->payload['reason'] ?? null,
            'linked_entity_type' => $event->payload['linked_entity_type'] ?? null,
            'linked_entity_id' => $event->payload['linked_entity_id'] ?? null,
            'pdf_ingest_log_id' => $event->payload['pdf_ingest_log_id'] ?? null,
            'filesystem_id' => $event->source_entity_id,
        ])->all();

        return [
            'days' => $days,
            'count' => count($arrivals),
            'arrivals' => $arrivals,
            'note' => $arrivals === []
                ? "Nothing reached the accounting inbox in the last {$days} days" . ($outcome !== '' ? " with outcome {$outcome}" : '') . '. Do not call again with the same arguments.'
                : null,
        ];
    }
}
