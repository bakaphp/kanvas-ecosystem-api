<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Moves a draft quote to SENT: allocates its quote number and freezes the customer snapshot. Does not email anything. */
#[AgentTool(name: 'Send Quote', category: 'accounting')]
class SendQuoteTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'send_quote',
            description: 'Marks a draft quote as SENT — this is what assigns its quote number, freezes the '
                . 'customer details onto it, and sets the validity date. It does NOT email the customer: '
                . 'generate_quote_pdf gives you the file to hand over. Once sent, the quote can only be '
                . 'accepted, rejected, expired, or revised.',
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $quote_id): array
    {
        $quote = $this->resolveQuote($quote_id);

        if (is_array($quote)) {
            return ['sent' => false, ...$quote];
        }

        if ($quote->customer_organization_id === null) {
            return [
                'sent' => false,
                'reason' => 'no_customer',
                'message' => "Quote {$quote_id} has no customer on it — it cannot be sent.",
            ];
        }

        /** @var Organization $customer */
        $customer = Organization::getByIdFromCompanyApp(
            $quote->customer_organization_id,
            $this->company,
            $this->app,
        );

        try {
            $sent = new SendQuoteAction(
                quote: $quote,
                billable: $customer,
                user: $this->contextUser(),
            )->execute();
        } catch (InvalidQuoteTransitionException $e) {
            return [
                'sent' => false,
                'reason' => 'invalid_transition',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'sent' => true,
            'quote_id' => $sent->getId(),
            'quote_number' => $sent->quote_number,
            'status' => $sent->status->value,
            'customer' => $sent->billable_display_name,
            'currency' => $sent->currency,
            'total' => $sent->total_native,
            'valid_until' => $sent->valid_until?->toDateString(),
        ];
    }
}
