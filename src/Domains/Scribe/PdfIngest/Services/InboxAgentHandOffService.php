<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Services;

use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\PdfIngest\Actions\ProposeBillFromPdfAction;
use Kanvas\Scribe\PdfIngest\Actions\RouteReceivablesDocumentAction;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Kanvas\Scribe\Setup\Actions\CreateAccountingAgentTaskAction;
use Kanvas\Scribe\Setup\Services\AccountingAgentLocatorService;
use Kanvas\Scribe\Setup\Services\AccountingSetupStateService;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * What the inbox could not finish becomes an agent's task: a bill left DRAFT goes to the AP agent with
 * what to fix (code it, find the vendor, ask the supplier for a corrected NCF), a customer document goes
 * to the AR agent. Everything else the inbox finished on its own and nobody is woken.
 *
 * Runs after the ingest committed. Best-effort: the document is already on the log and in the ledger, so
 * a task that fails to schedule is reported, never thrown back at the inbound email.
 */
class InboxAgentHandOffService
{
    public function handOff(PdfIngestLog $log): ?ScheduledAction
    {
        try {
            return match ($log->status) {
                PdfIngestStatusEnum::NEEDS_REVIEW => $this->billReview($log),
                PdfIngestStatusEnum::ROUTED_TO_AR => $this->receivables($log),
                default => null,
            };
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function billReview(PdfIngestLog $log): ?ScheduledAction
    {
        $bill = $log->linked_entity_type === 'bill'
            ? Bill::query()->where('id', $log->linked_entity_id)->where('companies_id', $log->companies_id)->first()
            : null;
        if ($bill === null) {
            return null;
        }

        $company = Companies::getById((int) $log->companies_id);
        $agent = new AccountingAgentLocatorService()->payables($bill->app, $company);
        if ($agent === null) {
            return null;
        }

        $recipient = $this->recipient($company);
        $code = $bill->metadata['ingest']['review_code'] ?? null;
        $reason = (string) ($log->rejected_reason ?? $bill->metadata['ingest']['review_reason'] ?? '');
        $from = $log->from_email !== null ? " from {$log->from_email}" : '';
        $header = "A supplier invoice{$from} reached the accounting inbox and became DRAFT bill {$bill->getId()} "
            . "({$bill->vendor_display_name}, {$bill->bill_number}, {$bill->currency} " . number_format((float) $bill->total_native, 2) . '), '
            . "but it could not go to approval: {$reason} ";

        $todo = match ($code) {
            ProposeBillFromPdfAction::REVIEW_NCF => 'Write to the supplier at the address on file for them and ask for a corrected invoice '
                . 'with a valid crédito fiscal NCF; keep it short and quote their invoice number. Add a note to the bill '
                . 'with add_bill_note saying you asked. When the corrected invoice arrives it comes through the inbox '
                . 'as a new bill: void this draft then.',
            ProposeBillFromPdfAction::REVIEW_RNC => 'Check the RNC with check_rnc and ask the supplier for the right one. Do not '
                . 'submit the bill until the RNC is registered at DGII.',
            ProposeBillFromPdfAction::REVIEW_VENDOR => 'Find the vendor with find_vendor. If it exists, tell '
                . "{$this->personName($recipient)} which vendor this is so the bill can be linked; if not, ask them whether to add it.",
            default => 'Work out the right expense account (look at the invoice file, the vendor\'s past bills with '
                . 'find_bill, the chart) and ask the approver to confirm it before the bill is coded and submitted.',
        };

        return new CreateAccountingAgentTaskAction(
            agent: $agent,
            onBehalfOf: $recipient,
            instruction: $header . $todo . " The invoice file is filesystem_id {$log->filesystem_id}.",
            sourceEntityType: Bill::class,
            sourceEntityId: $bill->getId(),
        )->execute();
    }

    private function receivables(PdfIngestLog $log): ?ScheduledAction
    {
        $routing = (array) ($log->extracted_payload[RouteReceivablesDocumentAction::ROUTING_KEY] ?? []);
        $agentId = (int) ($routing['agent_id'] ?? 0);
        $agent = $agentId > 0 ? Agent::query()->where('id', $agentId)->where('companies_id', $log->companies_id)->first() : null;
        if ($agent === null) {
            return null;
        }

        $recipient = $this->recipient(Companies::getById((int) $log->companies_id));
        $customer = (string) ($routing['customer_name'] ?? '') ?: 'a customer';
        $extracted = (array) $log->extracted_payload;
        $file = "filesystem_id {$log->filesystem_id}";

        $instruction = match ($log->document_type) {
            PdfIngestDocumentTypeEnum::REMITTANCE_ADVICE => $this->remittanceInstruction($customer, (array) ($routing['remittance'] ?? []), $file),
            PdfIngestDocumentTypeEnum::CUSTOMER_PO => "{$customer} sent a purchase order"
                . (! empty($extracted['po_number']) ? " ({$extracted['po_number']})" : '')
                . (isset($extracted['total']) ? ' for ' . ($extracted['currency'] ?? '') . ' ' . $extracted['total'] : '')
                . " to the accounting inbox ({$file}). Find the customer with find_customer, then draft the quote with "
                . 'create_quote, or the invoice with create_ar_invoice when the PO accepts a price already agreed, with the '
                . 'PO lines and the PO number as reference. Attach the PO file to it. Never issue it yourself: it goes to approval.',
            PdfIngestDocumentTypeEnum::CREDIT_REQUEST => "{$customer} sent a credit request form to the accounting inbox ({$file}). "
                . 'Read it with extract_credit_request_form, summarize the requested limit, terms and references, and ask '
                . "{$this->personName($recipient)} to decide. Do not grant credit on your own.",
            default => null,
        };

        if ($instruction === null) {
            return null;
        }

        return new CreateAccountingAgentTaskAction(
            agent: $agent,
            onBehalfOf: $recipient,
            instruction: $instruction,
            sourceEntityType: Filesystem::class,
            sourceEntityId: (int) $log->filesystem_id,
        )->execute();
    }

    /**
     * @param array<string, mixed> $match
     */
    private function remittanceInstruction(string $customer, array $match, string $file): string
    {
        $amount = isset($match['amount']) ? number_format((float) $match['amount'], 2) : 'an unstated amount';
        $invoices = array_merge($match['referenced_invoices'] ?? [], $match['open_invoices'] ?? []);
        $listed = implode(', ', array_unique(array_map(
            fn (array $invoice): string => "{$invoice['invoice_number']} (balance {$invoice['balance_due']})",
            $invoices,
        )));

        $matched = ! empty($match['exact_match'])
            ? "It matches invoice {$match['exact_match']} exactly."
            : ($listed !== '' ? "Open invoices for them: {$listed}." : 'No open invoice of theirs was found.');

        return "{$customer} sent remittance advice for {$amount}"
            . (! empty($match['payment_reference']) ? " (reference {$match['payment_reference']})" : '')
            . " to the accounting inbox ({$file}). {$matched} Do not record the payment: the bank match applies it "
            . 'when the money shows on the bank feed. Add a note to the invoice it pays with add_invoice_note so the '
            . 'match is expected, and if nothing matches or the amount differs, tell the person who owns this account.';
    }

    private function recipient(Companies $company): Users
    {
        return new AccountingSetupStateService()->approverOrOwner($company);
    }

    private function personName(Users $user): string
    {
        return $user->fullName() ?: (string) $user->email;
    }
}
