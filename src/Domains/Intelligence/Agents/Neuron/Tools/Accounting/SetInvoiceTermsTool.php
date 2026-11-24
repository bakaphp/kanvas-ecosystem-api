<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\PresentsInvoiceTerms;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesInvoiceForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoice as AmendInvoiceData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * A draft takes the new terms directly; an issued or sent invoice goes through AmendInvoiceAction, so the change
 * is an amendment on the ledger with its reason, the only post-issue mutation the domain allows. Paid and voided
 * invoices are closed.
 */
#[AgentTool(name: 'Set Invoice Terms', category: 'accounting')]
class SetInvoiceTermsTool extends Tool
{
    use HasKanvasContext;
    use PresentsInvoiceTerms;
    use ReportsToolOutcome;
    use ResolvesInvoiceForTool;
    use TrackByInputs;

    protected string $name = 'set_invoice_terms';

    protected ?string $description = 'Sets when an AR invoice is due: a due_date, or net_terms_days counted from its '
        . 'issue date. Works on a draft and on an issued or sent invoice (there it is recorded as an amendment, so give '
        . 'the reason the person stated); a paid or voided invoice cannot change. Amounts, lines and the customer never '
        . 'change here. Only on the user\'s explicit request.';

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
                description: 'The Kanvas invoice id (find_invoice returns it).',
                required: true,
            ),
            new ToolProperty(
                name: 'due_date',
                type: PropertyType::STRING,
                description: 'New due date, YYYY-MM-DD. Give this or net_terms_days.',
                required: false,
            ),
            new ToolProperty(
                name: 'net_terms_days',
                type: PropertyType::INTEGER,
                description: 'Days after the issue date until it is due (30 for Net 30). The due date is computed from it '
                    . 'when due_date is not given.',
                required: false,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why the terms change, in the person\'s words. Required once the invoice is issued.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $invoice_id,
        ?string $due_date = null,
        ?int $net_terms_days = null,
        ?string $reason = null,
    ): array {
        $invoice = $this->resolveInvoice($invoice_id);

        if (is_array($invoice)) {
            return $this->notFound(['changed' => false, ...$invoice]);
        }

        if (in_array($invoice->document_status, [InvoiceDocumentStatusEnum::PAID, InvoiceDocumentStatusEnum::VOIDED], true)) {
            return $this->denied(
                "Invoice {$invoice->getId()} is {$invoice->document_status->value}; its terms are closed.",
                ['changed' => false, 'reason' => 'invoice_closed', 'invoice_id' => $invoice->getId(), 'document_status' => $invoice->document_status->value],
                guidance: 'Say explicitly that the terms were NOT changed.'
            );
        }

        $dueDate = Str::trimToNull($due_date);
        $reason = Str::trimToNull($reason);

        if ($dueDate === null && $net_terms_days === null) {
            return $this->invalidArgs(
                'Give a due_date or net_terms_days.',
                ['changed' => false, 'reason' => 'terms_required', 'message' => 'Give a due_date (YYYY-MM-DD) or net_terms_days. Nothing was changed.'],
                guidance: 'The invoice was NOT changed.'
            );
        }

        if ($net_terms_days !== null && $net_terms_days < 0) {
            return $this->invalidArgs(
                'net_terms_days must be 0 or more.',
                ['changed' => false, 'reason' => 'invalid_terms', 'message' => 'net_terms_days must be 0 or more. Nothing was changed.'],
                guidance: 'The invoice was NOT changed.'
            );
        }

        // DateHelper::parseYmd() refuses "next week" and a rolled-over 2026-02-30 alike; Carbon::parse() takes both.
        $parsedDay = $dueDate !== null ? DateHelper::parseYmd($dueDate) : null;
        $parsedDue = $parsedDay !== null ? Carbon::instance($parsedDay) : null;

        if ($dueDate !== null && $parsedDue === null) {
            return $this->invalidArgs(
                "due_date \"{$dueDate}\" is not a date.",
                ['changed' => false, 'reason' => 'invalid_due_date', 'message' => "due_date \"{$dueDate}\" is not a date; use YYYY-MM-DD. Nothing was changed."],
                guidance: 'The invoice was NOT changed.'
            );
        }

        $issued = $invoice->issued_date ?? Carbon::today();
        $parsedDue ??= $issued->copy()->addDays((int) $net_terms_days);
        $previousDue = $invoice->due_date?->toDateString();

        if ($invoice->document_status !== InvoiceDocumentStatusEnum::DRAFT && $reason === null) {
            return $this->invalidArgs(
                'An issued invoice needs the reason for the change.',
                ['changed' => false, 'reason' => 'reason_required', 'message' => "Invoice {$invoice->getId()} is {$invoice->document_status->value}: changing its terms is an amendment and needs the reason the person gave. Ask, then call again with reason."],
                guidance: 'The invoice was NOT changed.'
            );
        }

        try {
            $invoice = $invoice->document_status === InvoiceDocumentStatusEnum::DRAFT
                ? $this->setOnDraft($invoice, $parsedDue, $net_terms_days)
                : new AmendInvoiceAction(
                    invoice: $invoice,
                    data: new AmendInvoiceData(reason: $reason, due_date: $parsedDue, net_terms_days: $net_terms_days),
                    user: $this->contextUser(),
                )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->invalidArgs(
                $e->getMessage(),
                ['changed' => false, 'reason' => 'change_failed', 'message' => 'The terms were NOT changed: ' . $e->getMessage()],
                guidance: 'The invoice was NOT changed.'
            );
        }

        return $this->ok([
            'changed' => true,
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->invoice_number,
            'document_status' => $invoice->document_status->value,
            'previous_due_date' => $previousDue,
            ...$this->invoiceTerms($invoice),
            'message' => $invoice->document_status === InvoiceDocumentStatusEnum::DRAFT
                ? 'Terms set on the draft; they go out with it when it is issued.'
                : 'Terms amended on the issued invoice and recorded on the ledger with the reason. The customer\'s copy '
                . 'already sent shows the old date: send_invoice again if they should see the new one.',
        ]);
    }

    private function setOnDraft(Invoice $invoice, Carbon $dueDate, ?int $netTermsDays): Invoice
    {
        $invoice->due_date = $dueDate;

        if ($netTermsDays !== null) {
            $invoice->net_terms_days = $netTermsDays;
        }

        $invoice->saveOrFail();
        $invoice->emitLedgerEvent('scribe.invoice.terms_set', payload: [
            'due_date' => $dueDate->toDateString(),
            'net_terms_days' => $invoice->net_terms_days,
        ]);

        return $invoice->refresh();
    }
}
