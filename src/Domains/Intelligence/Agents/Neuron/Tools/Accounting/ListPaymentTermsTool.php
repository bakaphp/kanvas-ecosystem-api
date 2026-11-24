<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\PresentsPaymentTerms;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\PaymentTerms\Models\PaymentTerm;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'List Payment Terms', category: 'accounting')]
class ListPaymentTermsTool extends Tool
{
    use HasKanvasContext;
    use PresentsPaymentTerms;
    use ReportsToolOutcome;

    protected string $name = 'list_payment_terms';

    protected ?string $description = 'Lists the company\'s payment terms (e.g. Net 30) and which one is the default: the '
        . 'default is what a new invoice, a SOW payment schedule and a billing schedule use when nothing names terms; '
        . 'with no default they are due on receipt. Change them with set_payment_terms.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        // No repeat guard: set_payment_terms changes the answer within a turn, and the read is one indexed query.
        $terms = PaymentTerm::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->notDeleted()
            ->orderByDesc('is_default')
            ->orderBy('net_days')
            ->get();

        return $this->ok([
            'count' => $terms->count(),
            'payment_terms' => $terms->map(fn (PaymentTerm $term): array => $this->presentPaymentTerm($term))->values()->all(),
            'message' => $this->message($terms),
        ]);
    }

    /**
     * @param Collection<int, PaymentTerm> $terms
     */
    private function message(Collection $terms): string
    {
        return match (true) {
            $terms->isEmpty() => 'No payment terms yet: new invoices and schedules are due on receipt. set_payment_terms '
                . 'with is_default (e.g. "Net 30", 30 days) gives them a default.',
            $terms->firstWhere('is_default', true) === null => 'None is the default, so new invoices and schedules are '
                . 'due on receipt until one is made the default.',
            default => 'The default applies to new invoices and schedules that name no terms.',
        };
    }
}
