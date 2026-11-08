<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Actions;

use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Services\OpenInvoiceMatcherService;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Kanvas\Scribe\PdfIngest\Traits\ExtractsPdfPayloadValuesTrait;
use Kanvas\Scribe\Setup\Services\AccountingAgentLocatorService;
use Throwable;

/**
 * A customer document from the accounting inbox goes to the AR agent, not into AP (decision 22). A
 * remittance is matched to the customer's open invoices here and each matched invoice gets a ledger
 * line; nothing is applied, because only the bank movement proves the money arrived. The match is kept
 * on the log under `extracted_payload.ar_routing` for the AR agent's task (InboxAgentHandOffService).
 */
class RouteReceivablesDocumentAction
{
    use ExtractsPdfPayloadValuesTrait;

    public const string ROUTING_KEY = 'ar_routing';

    /**
     * @param array<string, mixed> $extracted
     */
    public function __construct(
        private readonly PdfIngestLog $log,
        private readonly PdfIngestInput $input,
        private readonly array $extracted,
    ) {
    }

    public function execute(): void
    {
        $routing = ['customer_name' => $this->customerName()];

        if ($this->log->document_type === PdfIngestDocumentTypeEnum::REMITTANCE_ADVICE) {
            $routing['remittance'] = $this->matchRemittance($routing['customer_name']);
        }

        $agent = new AccountingAgentLocatorService()->receivables($this->input->app, $this->input->company);
        $routing['agent_id'] = $agent?->getId();

        $this->log->extracted_payload = [...(array) $this->log->extracted_payload, self::ROUTING_KEY => $routing];
        $this->log->status = PdfIngestStatusEnum::ROUTED_TO_AR;
        $this->log->rejected_reason = $agent === null
            ? 'Customer document, but this company has no AR agent to hand it to.'
            : null;
    }

    private function customerName(): string
    {
        return $this->trimOrDefault(
            $this->extracted['customer_name'] ?? null,
            $this->trimOrDefault($this->extracted['vendor_name'] ?? null, $this->input->fromName ?? $this->input->fromEmail ?? ''),
        ) ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function matchRemittance(string $customer): array
    {
        $numbers = array_values(array_filter(array_map(
            fn (mixed $number): string => trim((string) $number),
            (array) ($this->extracted['referenced_invoice_numbers'] ?? []),
        )));

        $match = new OpenInvoiceMatcherService()->match(
            app: $this->input->app,
            company: $this->input->company,
            customer: $customer,
            amount: $this->floatOrNull($this->extracted['total'] ?? null),
            invoiceNumbers: $numbers,
        );
        $match['payment_reference'] = $this->trimOrDefault($this->extracted['payment_reference'] ?? null, null);

        foreach ($match['referenced_invoices'] as $invoice) {
            $this->recordRemittance((int) $invoice['invoice_id'], $match);
        }

        return $match;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function recordRemittance(int $invoiceId, array $match): void
    {
        try {
            new AppendEventAction(
                new EventData(
                    app: $this->input->app,
                    company: $this->input->company,
                    sourceDomain: 'Scribe',
                    eventType: 'scribe.invoice.remittance_received',
                    sourceEntityType: Invoice::class,
                    sourceEntityId: $invoiceId,
                    actorType: 'System',
                    payload: [
                        'pdf_ingest_log_id' => $this->log->id,
                        'filesystem_id' => (int) $this->input->pdf->getKey(),
                        'amount' => $match['amount'],
                        'payment_reference' => $match['payment_reference'],
                        'from_email' => $this->input->fromEmail,
                    ],
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
