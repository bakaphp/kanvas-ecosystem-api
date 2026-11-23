<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesVariantForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Scribe\Delivery\Exceptions\DocumentDeliveryException;
use Kanvas\Scribe\Quotes\Actions\CreatePersonQuoteAction;
use Kanvas\Scribe\Quotes\DataTransferObject\QuoteLine;
use Kanvas\Scribe\Quotes\Models\QuoteLine as QuoteLineModel;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Spatie\LaravelData\DataCollection;

/**
 * The quote tool a customer-facing agent may hold. The prospect steers the agent, so the two things
 * that decide a quote are out of the model's hands: the customer is whoever the conversation is with,
 * and every price is the catalog's. Granted per agent from the catalog, never part of a baseline.
 */
#[AgentTool(name: 'Create Customer Quote', category: 'crm')]
class CreateCustomerQuoteTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesVariantForTool;
    use TrackByInputs;

    private const int MAX_LINES = 25;
    private const float MAX_QUANTITY = 10000.0;
    private const string HAND_OFF = 'Do not promise a quote; offer to hand them to a person.';

    protected string $name = 'create_customer_quote';

    protected ?string $description = 'Creates a quote for the customer you are talking to and returns the link to its page. '
        . 'Pass the variant_id and quantity of each product they want (find them with the product search tools). '
        . 'The price always comes from the catalog: you cannot set, change or discount it. Use it only when the '
        . 'customer asks for a quote or a formal price on specific products. Give them the returned link and the '
        . 'totals exactly as returned; never send a file and never state a price the quote does not show.';

    /**
     * Both come from the conversation the agent is in, filled by the tool registry.
     */
    public function __construct(
        private readonly ?Lead $lead = null,
        private readonly ?People $person = null,
    ) {
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'lines',
                description: 'The products being quoted. At least one.',
                required: true,
                items: new ObjectProperty(
                    name: 'line',
                    description: 'One product on the quote.',
                    properties: [
                        new ToolProperty(
                            name: 'variant_id',
                            type: PropertyType::INTEGER,
                            description: 'The variant id from the product search tools. Never invent one.',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'quantity',
                            type: PropertyType::NUMBER,
                            description: 'How many units. Defaults to 1.',
                            required: false,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Text that prints on the quote, e.g. what the customer asked for. '
                    . 'Never prices or discounts.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, mixed> $lines
     *
     * @return array<string, mixed>
     */
    public function __invoke(array $lines, ?string $notes = null): array
    {
        if (! $this->hasTenantContext()) {
            return ['created' => false, ...$this->tenantContextMissingError('quote')];
        }

        $customer = $this->customer();

        if ($customer === null) {
            return $this->denied(
                'This conversation is not tied to a customer on file, so no quote was made.',
                ['created' => false, 'reason' => 'no_customer_in_conversation'],
                self::HAND_OFF
            );
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            return $this->invalidArgs(
                'A quote needs between 1 and ' . self::MAX_LINES . ' products.',
                ['created' => false, 'reason' => 'lines_invalid']
            );
        }

        $quoteLines = [];

        foreach ($lines as $line) {
            $quoteLine = $this->catalogLine($line);

            if (is_array($quoteLine)) {
                return $quoteLine;
            }

            $quoteLines[] = $quoteLine;
        }

        $action = new CreatePersonQuoteAction(
            people: $customer,
            lines: new DataCollection(QuoteLine::class, $quoteLines),
            currency: $this->company->currency?->code ?? 'USD',
            user: $this->user,
            notes: $notes,
        );

        try {
            $quote = $action->execute();
        } catch (DocumentDeliveryException $e) {
            return $this->failed(
                'The quote could not be shared with the customer, so none was sent.',
                ['created' => false, 'reason' => $e->reason],
                self::HAND_OFF
            );
        }

        return $this->ok(
            [
                'created' => true,
                'quote_number' => $quote->quote_number,
                'currency' => $quote->currency,
                'total' => (float) $quote->total_native,
                'valid_until' => $quote->valid_until?->toDateString(),
                'lines' => $quote->lines
                    ->map(fn (QuoteLineModel $line): array => [
                        'description' => $line->description,
                        'quantity' => (float) $line->quantity,
                        'unit_price' => (float) $line->unit_price_native,
                        'line_total' => (float) $line->line_total_native,
                    ])
                    ->all(),
                'link' => $action->link?->link,
            ],
            'Give the customer this link and these totals exactly as returned. Do not send a file.'
        );
    }

    private function customer(): ?People
    {
        $customer = $this->person ?? $this->lead?->people;

        $sameTenant = $customer !== null
            && (int) $customer->apps_id === $this->app->getId()
            && (int) $customer->companies_id === $this->company->getId();

        return $sameTenant ? $customer : null;
    }

    /**
     * @return QuoteLine|array<string, mixed> the line, or the refusal the model is handed
     */
    private function catalogLine(mixed $line): QuoteLine|array
    {
        $variantId = is_array($line) ? ($line['variant_id'] ?? null) : null;
        $quantity = is_array($line) ? (float) ($line['quantity'] ?? 1) : 0.0;
        $quantityAllowed = $quantity > 0 && $quantity <= self::MAX_QUANTITY;

        if (! is_numeric($variantId) || ! $quantityAllowed) {
            return $this->invalidArgs(
                'Every line needs a variant_id and a quantity above zero.',
                ['created' => false, 'reason' => 'lines_invalid']
            );
        }

        $variant = $this->resolveVariantOrError((int) $variantId);

        if (is_array($variant)) {
            return $this->notFound(
                ['created' => false, 'reason' => 'variant_not_found', 'error' => $variant['message']]
            );
        }

        $price = $this->catalogPrice($variant);

        if ($price === null) {
            return $this->denied(
                "\"{$variant->name}\" has no price in the catalog, so it was not quoted.",
                ['created' => false, 'reason' => 'variant_not_priced'],
                self::HAND_OFF
            );
        }

        return new QuoteLine(
            description: $this->describe($variant),
            quantity: $quantity,
            unit_price_native: $price,
            sku: $variant->sku,
            metadata: ['variant_id' => $variant->getId()],
        );
    }

    /**
     * The same price the product search tools show, so the quote never disagrees with the chat.
     */
    private function catalogPrice(Variants $variant): ?float
    {
        try {
            $price = (float) $variant->getPriceInfoFromDefaultChannel()->price;
        } catch (ModelNotFoundException) {
            return null;
        }

        return $price > 0 ? $price : null;
    }

    private function describe(Variants $variant): string
    {
        $product = (string) $variant->product?->name;

        return $product === '' || $product === $variant->name
            ? (string) $variant->name
            : $product . ' - ' . $variant->name;
    }
}
