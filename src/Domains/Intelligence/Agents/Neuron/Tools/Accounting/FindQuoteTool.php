<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTenantRecordForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\Quotes\Models\QuoteLine;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Looks up ONE quote by its number and returns the detail an agent needs to act on it — most
 * importantly the quote_id, which every other quote tool takes.
 */
#[AgentTool(name: 'Find Quote', category: 'accounting')]
class FindQuoteTool extends Tool implements HasRunKey
{
    use FindsTenantRecordForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_quote',
            description: 'Look up a single quote by its quote number and return the full detail: customer, '
                . 'status (draft/sent/accepted/rejected/expired/converted), totals, validity date, line items, '
                . 'and the invoice it became if it was converted. Use it to get the quote_id the other quote '
                . 'tools need. Returns found=false when there is no such quote.',
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
                name: 'quote_number',
                type: PropertyType::STRING,
                description: 'The quote number to look up. Drafts have none — they can only be reached by the '
                    . 'quote_id create_quote returned.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $quote_number): array
    {
        $result = $this->findTenantRecordOrNotFound(
            Quote::class,
            'quote_number',
            $quote_number,
            'quote'
        );

        if (is_array($result)) {
            return $result;
        }

        /** @var Quote $quote */
        $quote = $result;

        return [
            'found' => true,
            'quote_id' => $quote->getId(),
            'quote_number' => $quote->quote_number,
            'customer' => $quote->billable_display_name ?? $quote->customer?->name,
            'status' => $quote->status->value,
            'currency' => $quote->currency,
            'subtotal' => $quote->subtotal_native,
            'tax' => $quote->tax_native,
            'total' => $quote->total_native,
            'issued_date' => $quote->issued_date?->toDateString(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'converted_to_invoice_id' => $quote->converted_to_invoice_id,
            'lines' => $quote->lines->map(fn (QuoteLine $line): array => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price_native,
                'total' => $line->line_total_native,
            ])->all(),
        ];
    }
}
