<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use Kanvas\Scribe\Quotes\Actions\AcceptQuoteAction;
use Kanvas\Scribe\Quotes\Actions\RejectQuoteAction;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Records the customer's answer to a sent quote — accepted (the only path to an invoice) or rejected. */
#[AgentTool(name: 'Answer Quote', category: 'accounting')]
class AnswerQuoteTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'answer_quote',
            description: 'Records what the CUSTOMER decided about a quote you already sent: accepted or '
                . 'rejected. Only call it when someone tells you the customer actually answered — this is '
                . 'their decision, never yours to assume. Accepting is what unlocks '
                . 'convert_quote_to_invoice; rejecting closes the quote for good.',
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
                name: 'accepted',
                type: PropertyType::BOOLEAN,
                description: 'True when the customer accepted the quote, false when they turned it down.',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why the customer said no, in their own words. Only used when accepted is false.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $quote_id, bool $accepted, ?string $reason = null): array
    {
        $quote = $this->resolveQuote($quote_id);

        if (is_array($quote)) {
            return ['recorded' => false, ...$quote];
        }

        try {
            $answered = $accepted
                ? new AcceptQuoteAction(quote: $quote, user: $this->contextUser())->execute()
                : new RejectQuoteAction(
                    quote: $quote,
                    lostReason: $reason,
                    user: $this->contextUser(),
                )->execute();
        } catch (InvalidQuoteTransitionException $e) {
            return [
                'recorded' => false,
                'reason' => 'invalid_transition',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'recorded' => true,
            'quote_id' => $answered->getId(),
            'quote_number' => $answered->quote_number,
            'status' => $answered->status->value,
            'total' => $answered->total_native,
            'message' => $accepted
                ? 'Quote accepted. Call convert_quote_to_invoice to turn it into a draft invoice.'
                : 'Quote rejected — it is closed and cannot be reopened. Issue a new quote if they come back.',
        ];
    }
}
