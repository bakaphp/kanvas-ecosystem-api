<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use Kanvas\Scribe\Quotes\Actions\AcceptQuoteAction;
use Kanvas\Scribe\Quotes\Actions\RejectQuoteAction;
use Kanvas\Scribe\Quotes\Actions\SendQuoteAction;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use Kanvas\Scribe\Quotes\Models\Quote;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/** Records the customer's answer to a sent quote — accepted (the only path to an invoice) or rejected. */
#[AgentTool(name: 'Answer Quote', category: 'accounting')]
class AnswerQuoteTool extends Tool
{
    use HasKanvasContext;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    protected string $name = 'answer_quote';

    protected ?string $description = 'Records what the CUSTOMER decided about a quote you already sent: accepted or '
        . 'rejected. Only call it when someone tells you the customer actually answered — this is '
        . 'their decision, never yours to assume. Accepting is what unlocks '
        . 'convert_quote_to_invoice (or, for a quote with a SOW, bills its payment schedule); rejecting closes the quote '
        . 'for good. When a person tells you the customer accepted a quote that was never sent through Kanvas (signed by '
        . 'email, agreed in person), pass accepted_outside_kanvas: it numbers the quote and records it as sent and '
        . 'accepted, so the invoice is made from it. Never rebuild a quote\'s lines with create_ar_invoice.';

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
                name: 'accepted_outside_kanvas',
                type: PropertyType::BOOLEAN,
                description: 'True only when a person says the customer already accepted this quote outside Kanvas while it '
                    . 'was still a draft. Never set it on your own.',
                required: false,
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
    public function __invoke(
        int $quote_id,
        bool $accepted,
        ?bool $accepted_outside_kanvas = null,
        ?string $reason = null,
    ): array {
        $quote = $this->resolveQuote($quote_id);

        if (is_array($quote)) {
            return ['recorded' => false, ...$quote];
        }

        if ($accepted && $quote->status === QuoteStatusEnum::DRAFT) {
            if (! ($accepted_outside_kanvas ?? false)) {
                return [
                    'recorded' => false,
                    'reason' => 'never_sent',
                    'message' => "Quote {$quote->getId()} was never sent, so the customer has not answered it in Kanvas. If the "
                        . 'person says the customer already accepted it outside Kanvas, call answer_quote again with '
                        . 'accepted_outside_kanvas=true; otherwise send it with send_quote and wait for their answer.',
                ];
            }

            if ($quote->customer === null) {
                return [
                    'recorded' => false,
                    'reason' => 'no_customer',
                    'message' => "Quote {$quote->getId()} has no customer, so it cannot be recorded as accepted. Set its customer first.",
                ];
            }

            $quote = $this->markSentOutsideKanvas($quote);
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
            'invoices' => $accepted ? $answered->invoiceSummaries() : [],
            'message' => $accepted
                ? ($answered->sow_message_id !== null
                    ? 'Quote accepted. Its SOW payment schedule bills it: every payment already due is invoiced in the background (`invoices` lists those made so far), later ones on their dates. Tell the person; do not convert it or create another invoice.'
                    : 'Quote accepted. Call convert_quote_to_invoice to turn it into a draft invoice.')
                : 'Quote rejected — it is closed and cannot be reopened. Issue a new quote if they come back.',
        ];
    }

    /**
     * Numbered and recorded as sent without delivering anything (what "mark as sent" does), so the acceptance and the
     * invoice that follows hang off the quote; the ledger says a person reported it.
     */
    private function markSentOutsideKanvas(Quote $quote): Quote
    {
        $sent = new SendQuoteAction(
            quote: $quote,
            billable: $quote->customer,
            user: $this->contextUser(),
        )->execute();

        $sent->emitLedgerEvent('scribe.quote.accepted_outside_kanvas', payload: [
            'quote_number' => $sent->quote_number,
            'reported_by_users_id' => $this->contextUser()?->getId(),
        ]);

        return $sent;
    }
}
