<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCustomerForTool;
use Kanvas\Scribe\Quotes\Actions\CreateQuoteAction;
use Kanvas\Scribe\Quotes\DataTransferObject\Quote as QuoteData;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLine as QuoteLineData;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Spatie\LaravelData\DataCollection;

/** Creates a DRAFT multi-line quote for a customer. Nothing leaves Kanvas and no JE posts — quotes are pre-economic-event. */
#[AgentTool(name: 'Create Quote', category: 'accounting')]
class CreateQuoteTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesCustomerForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_quote',
            description: 'Creates a DRAFT quote (sales proposal) for a customer, with one or more priced lines. '
                . 'It stops at draft: no quote number is assigned and nothing is sent until you call send_quote. '
                . 'A quote never touches the books — use create_ar_invoice when the customer is being billed for '
                . 'work already agreed, and this when you are still proposing.',
        );
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'customer_name',
                type: PropertyType::STRING,
                description: 'Customer name to match. Always required — never guess or pick an arbitrary '
                    . 'customer; ask the user which one if it is not clear from context.',
                required: true,
            ),
            new ArrayProperty(
                name: 'lines',
                description: 'What is being quoted. At least one line is required.',
                required: true,
                items: new ObjectProperty(
                    name: 'line',
                    description: 'A single quote line.',
                    properties: [
                        new ToolProperty(
                            name: 'description',
                            type: PropertyType::STRING,
                            description: 'What this line is for, e.g. "Onboarding + data migration".',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'unit_price',
                            type: PropertyType::NUMBER,
                            description: 'Price for ONE unit, before tax and discount.',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'quantity',
                            type: PropertyType::NUMBER,
                            description: 'How many units. Defaults to 1.',
                            required: false,
                        ),
                        new ToolProperty(
                            name: 'discount_amount',
                            type: PropertyType::NUMBER,
                            description: 'Discount for this line, as an amount (not a percentage). Defaults to 0.',
                            required: false,
                        ),
                        new ToolProperty(
                            name: 'tax_amount',
                            type: PropertyType::NUMBER,
                            description: 'Tax for this line, as an amount (not a rate). Defaults to 0.',
                            required: false,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Currency code. Defaults to USD.',
                required: false,
            ),
            new ToolProperty(
                name: 'valid_until',
                type: PropertyType::STRING,
                description: 'Last day the quote stands, as YYYY-MM-DD. Defaults to 30 days after it is sent.',
                required: false,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Text that prints on the quote itself — scope, assumptions, what is included.',
                required: false,
            ),
            new ToolProperty(
                name: 'terms',
                type: PropertyType::STRING,
                description: 'Payment / commercial terms that print on the quote, e.g. "50% upfront, net 30".',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, mixed> $lines
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $customer_name,
        array $lines,
        ?string $currency = null,
        ?string $valid_until = null,
        ?string $notes = null,
        ?string $terms = null,
    ): array {
        if (! $this->hasTenantContext()) {
            return ['created' => false, ...$this->tenantContextMissingError('quote')];
        }

        if (trim($customer_name) === '') {
            return [
                'created' => false,
                'reason' => 'customer_name_required',
                'message' => 'A customer_name is required — never pick an arbitrary customer.',
            ];
        }

        $lineData = $this->buildLines($lines);

        if ($lineData === []) {
            return [
                'created' => false,
                'reason' => 'lines_required',
                'message' => 'A quote needs at least one line with a description and a unit_price.',
            ];
        }

        $customer = $this->resolveCustomerOrError($customer_name);

        if (is_array($customer)) {
            return ['created' => false, ...$customer];
        }

        $quote = new CreateQuoteAction(
            data: new QuoteData(
                app: $this->app,
                company: $this->company,
                billable: $customer,
                lines: new DataCollection(QuoteLineData::class, $lineData),
                currency: $currency !== null && trim($currency) !== '' ? strtoupper(trim($currency)) : 'USD',
                fx_rate_to_base: 1.0,
                issued_date: Carbon::today(),
                valid_until: $valid_until !== null ? Carbon::parse($valid_until) : null,
                notes: $notes,
                terms: $terms,
            ),
            user: $this->contextUser(),
        )->execute();

        return [
            'created' => true,
            'quote_id' => $quote->getId(),
            'status' => $quote->status->value,
            'customer' => $customer->name,
            'currency' => $quote->currency,
            'subtotal' => $quote->subtotal_native,
            'tax' => $quote->tax_native,
            'total' => $quote->total_native,
            'lines' => count($lineData),
            'message' => 'Draft quote created. It has no quote number yet — call send_quote to number it and '
                . 'mark it sent, or generate_quote_pdf to hand the customer a copy first.',
        ];
    }

    /**
     * @param array<int, mixed> $lines
     *
     * @return list<QuoteLineData>
     */
    private function buildLines(array $lines): array
    {
        $built = [];

        foreach ($lines as $line) {
            if (! is_array($line) || ! isset($line['unit_price']) || ! is_numeric($line['unit_price'])) {
                continue;
            }

            $built[] = new QuoteLineData(
                description: trim((string) ($line['description'] ?? '')),
                quantity: isset($line['quantity']) && is_numeric($line['quantity']) ? (float) $line['quantity'] : 1.0,
                unit_price_native: (float) $line['unit_price'],
                discount_amount_native: isset($line['discount_amount']) && is_numeric($line['discount_amount'])
                    ? (float) $line['discount_amount']
                    : 0.0,
                tax_amount_native: isset($line['tax_amount']) && is_numeric($line['tax_amount'])
                    ? (float) $line['tax_amount']
                    : 0.0,
            );
        }

        return $built;
    }
}
