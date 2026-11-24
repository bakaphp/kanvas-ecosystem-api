<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\PresentsPaymentTerms;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\PaymentTerms\Actions\CreatePaymentTermAction;
use Kanvas\Scribe\PaymentTerms\Actions\UpdatePaymentTermAction;
use Kanvas\Scribe\PaymentTerms\DataTransferObject\PaymentTerm as PaymentTermData;
use Kanvas\Scribe\PaymentTerms\Models\PaymentTerm;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Creates a payment term, or changes the one with that name; a term made the default replaces the previous default. */
#[AgentTool(name: 'Set Payment Terms', category: 'accounting')]
class SetPaymentTermsTool extends Tool
{
    use HasKanvasContext;
    use PresentsPaymentTerms;
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'set_payment_terms';

    protected ?string $description = 'Creates or changes one of the company\'s payment terms by name ("Net 30" with '
        . 'net_days 30). With is_default it becomes what every new invoice, SOW payment schedule and billing schedule '
        . 'uses when nothing names terms; invoices already made keep theirs (set_invoice_terms changes one). Only on the '
        . 'user\'s explicit request, e.g. "our standard terms are net 30".';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The term\'s name, e.g. "Net 30", "Due on receipt", "2/10 Net 30". An existing name is updated.',
                required: true,
            ),
            new ToolProperty(
                name: 'net_days',
                type: PropertyType::INTEGER,
                description: 'Days after the issue date until an invoice is due. 0 is due on receipt.',
                required: true,
            ),
            new ToolProperty(
                name: 'is_default',
                type: PropertyType::BOOLEAN,
                description: 'True makes it the company default for new invoices and schedules.',
                required: false,
            ),
            new ToolProperty(
                name: 'discount_days',
                type: PropertyType::INTEGER,
                description: 'Early-payment window in days, when the term has one (the 10 in "2/10 Net 30").',
                required: false,
            ),
            new ToolProperty(
                name: 'discount_pct',
                type: PropertyType::NUMBER,
                description: 'Early-payment discount percent (the 2 in "2/10 Net 30").',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $name,
        int $net_days,
        ?bool $is_default = null,
        ?int $discount_days = null,
        ?float $discount_pct = null,
    ): array {
        $name = Str::trimToNull($name);

        if ($name === null || $net_days < 0) {
            return $this->invalidArgs(
                'A name and net_days of 0 or more are required.',
                ['saved' => false, 'reason' => 'invalid_terms', 'message' => 'Give a name and net_days of 0 or more. Nothing was saved.'],
                guidance: 'Nothing was saved.'
            );
        }

        $existing = PaymentTerm::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('name', $name)
            ->notDeleted()
            ->first();

        $data = new PaymentTermData(
            app: $this->app,
            company: $this->company,
            name: $name,
            net_days: $net_days,
            discount_days: $discount_days ?? $existing?->discount_days,
            discount_pct: $discount_pct ?? $existing?->discount_pct,
            is_default: $is_default ?? (bool) ($existing?->is_default ?? false),
        );

        try {
            $term = $existing !== null
                ? new UpdatePaymentTermAction($existing, $data, $this->contextUser())->execute()
                : new CreatePaymentTermAction($data, $this->contextUser())->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->invalidArgs(
                $e->getMessage(),
                ['saved' => false, 'reason' => 'save_failed', 'message' => 'The payment term was NOT saved: ' . $e->getMessage()],
                guidance: 'Nothing was saved.'
            );
        }

        return $this->ok([
            'saved' => true,
            'action' => $existing !== null ? 'updated' : 'created',
            ...$this->presentPaymentTerm($term),
            'message' => $term->is_default
                ? "\"{$term->name}\" is the company default: new invoices, SOW payment schedules and billing schedules that "
                . 'name no terms are due ' . $term->net_days . ' days after issue. Invoices already made keep their dates.'
                : "\"{$term->name}\" saved. It is not the default; name it where it applies, or set is_default to make it one.",
        ]);
    }
}
