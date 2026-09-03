<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use Kanvas\Scribe\Quotes\Actions\ConvertQuoteToInvoiceAction;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Turns an ACCEPTED quote into a DRAFT invoice, carrying its lines over. Stops at draft — issuing is a separate, human step. */
#[AgentTool(name: 'Convert Quote To Invoice', category: 'accounting')]
class ConvertQuoteToInvoiceTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'convert_quote_to_invoice',
            description: 'Turns a quote the customer ACCEPTED into a draft invoice with the same lines, prices '
                . 'and taxes. The quote must already be accepted (answer_quote). The invoice stops at draft: no '
                . 'accounting entry posts and nothing is pushed anywhere until a human issues it.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'quote_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas quote id (returned as quote_id by create_quote or find_quote).',
                required: true,
            ),
            new ToolProperty(
                name: 'net_terms_days',
                type: PropertyType::INTEGER,
                description: 'How many days the customer has to pay, counted from the issue date. Defaults to 30.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $quote_id, ?int $net_terms_days = null): array
    {
        $quote = $this->resolveQuote($quote_id);

        if (is_array($quote)) {
            return ['converted' => false, ...$quote];
        }

        try {
            $invoice = new ConvertQuoteToInvoiceAction(
                quote: $quote,
                netTermsDays: $net_terms_days ?? 30,
                user: $this->contextUser(),
            )->execute();
        } catch (InvalidQuoteTransitionException $e) {
            return [
                'converted' => false,
                'reason' => 'invalid_transition',
                'message' => $e->getMessage() . ' Only an accepted quote can be converted.',
            ];
        }

        return [
            'converted' => true,
            'quote_id' => $quote->getId(),
            'quote_number' => $quote->quote_number,
            'invoice_id' => $invoice->getId(),
            'invoice_status' => $invoice->document_status->value,
            'currency' => $invoice->currency,
            'total' => $invoice->total_native,
            'due_date' => $invoice->due_date?->toDateString(),
            'message' => 'Draft invoice created from the quote. It has no invoice number and posts nothing to '
                . 'the books until a human issues it.',
        ];
    }
}
