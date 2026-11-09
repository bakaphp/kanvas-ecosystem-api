<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesInvoiceForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Invoices\Actions\DiscardDraftInvoiceAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Discard Draft Invoice', category: 'accounting')]
class DiscardDraftInvoiceTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesInvoiceForTool;
    use TrackByInputs;

    protected string $name = 'discard_draft_invoice';

    protected ?string $description = 'Withdraws a DRAFT invoice that should not go out (made by mistake, superseded, wrong '
        . 'amounts): its pending approval is cancelled and the draft removed. Only on explicit request. A draft posted '
        . 'nothing, so this is not a void: an issued or sent invoice is undone with void_ar_invoice, and a draft that '
        . 'already carries a fiscal number cannot be discarded.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'invoice_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas invoice id of the draft (from find_invoice, create_ar_invoice or a quote\'s invoices).',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why it is discarded, in the person\'s words.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id, ?string $reason = null): array
    {
        $invoice = $this->resolveInvoice($invoice_id);
        if (is_array($invoice)) {
            return $this->notFound($invoice, guidance: 'Nothing was discarded. Confirm the id with find_invoice.');
        }

        try {
            new DiscardDraftInvoiceAction(
                invoice: $invoice,
                user: $this->contextUser(),
                reason: Str::trimToNull($reason),
            )->execute();
        } catch (ValidationException $e) {
            return $this->denied($e->getMessage(), guidance: 'Say the draft was NOT discarded and why.');
        } catch (Throwable $e) {
            report($e);

            return $this->denied(
                "Discarding invoice {$invoice->getId()} failed: {$e->getMessage()}",
                guidance: 'Say the draft was NOT discarded.'
            );
        }

        return $this->ok(
            ['invoice_id' => $invoice->getId(), 'quote_id' => $invoice->quote_id],
            'The draft is gone and its approval withdrawn. Nothing was posted or sent, so there is nothing to reverse.'
        );
    }
}
