<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesScribeQuoteForTool;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Quotes\Actions\ConvertQuoteToInvoiceAction;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Kanvas\Scribe\Quotes\Exceptions\InvalidQuoteTransitionException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Turns an ACCEPTED quote into a draft invoice with its lines and submits it for approval; a person issues it. */
#[AgentTool(name: 'Convert Quote To Invoice', category: 'accounting')]
class ConvertQuoteToInvoiceTool extends Tool
{
    use HasKanvasContext;
    use ResolvesScribeQuoteForTool;
    use TrackByInputs;

    protected string $name = 'convert_quote_to_invoice';

    protected ?string $description = 'Turns a quote the customer ACCEPTED into a draft invoice with the same lines, prices '
        . 'and taxes, and submits it for a person\'s approval. The quote must already be accepted (answer_quote). '
        . 'No accounting entry posts and nothing is pushed anywhere until the approver issues it.';

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

        if ($quote->sow_message_id !== null && $quote->status === QuoteStatusEnum::ACCEPTED) {
            return [
                'converted' => false,
                'reason' => 'billed_by_sow',
                'quote_status' => $quote->status->value,
                'invoices' => $quote->invoiceSummaries(),
                'message' => "Quote {$quote->getId()} is accepted and has a SOW, so its payment schedule bills it: `invoices` "
                    . 'are the ones already made (drafts wait for approval); a payment due now that is not listed is still '
                    . 'being invoiced in the background, and later ones come on their dates. Tell the person exactly that. '
                    . 'Converting it or creating another invoice would bill the customer twice.',
            ];
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
                'quote_status' => $quote->status->value,
                'message' => $e->getMessage() . ' Only an accepted quote can be converted. Ask the person whether the '
                    . 'customer accepted it; if they did, record it with answer_quote and call this again. Do not '
                    . 'rebuild the quote with create_ar_invoice: the invoice must come from the quote, line by line.',
            ];
        }

        // Same path as create_ar_invoice: without it the draft never reaches an approver and nobody can issue it.
        $submitError = null;

        try {
            $invoice = ApprovalOriginService::during(
                ApprovalOriginEnum::AGENT,
                fn () => new SubmitInvoiceForApprovalAction($invoice, $this->contextUser())->execute()
            );
        } catch (Throwable $e) {
            report($e);
            $submitError = $e->getMessage();
        }

        $pending = $invoice->pendingApproval() !== null;

        return [
            'converted' => true,
            'quote_id' => $quote->getId(),
            'quote_number' => $quote->quote_number,
            'invoice_id' => $invoice->getId(),
            'invoice_status' => $invoice->document_status->value,
            'pending_approval' => $pending,
            'currency' => $invoice->currency,
            'total' => $invoice->total_native,
            'due_date' => $invoice->due_date?->toDateString(),
            'message' => match (true) {
                $submitError !== null => 'Draft invoice created from the quote, but it could not be submitted for approval: '
                    . $submitError . ' Say so; it is not issued.',
                $pending => 'Draft invoice created from the quote and sent to a person for approval; it is numbered and '
                    . 'issued when they approve it. Say so; do not claim it was issued.',
                default => 'Invoice created from the quote; no approval step applied.',
            },
        ];
    }
}
