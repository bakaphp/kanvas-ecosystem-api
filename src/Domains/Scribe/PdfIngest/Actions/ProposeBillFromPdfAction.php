<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\PdfIngest\Traits\ExtractsPdfPayloadValuesTrait;
use Spatie\LaravelData\DataCollection;

/**
 * Writes a DRAFT Bill from the LLM-extracted PDF payload.
 *
 * Bill lands with:
 *   - document_status=DRAFT (operator reviews + receives via normal Bills dashboard flow)
 *   - source='parsed_pdf'
 *   - pdf_ingest_log_id linking back to the ingest log row
 *   - external_id=<filesystem_uuid> for cross-system idempotency
 *   - vendor reference NULL until operator resolves it during review (LLM gave us a name; vendor row
 *     in Guild has to be created/picked separately — UI prompts the operator)
 *   - Each line points at the TRAVEL_AND_MEALS default expense account (operator reassigns)
 *   - metadata.ingest carries the raw LLM payload + vendor_name string + vendor_tax_id
 *
 * Returns null when extraction is unusable (no total, no expense account in COA).
 */
class ProposeBillFromPdfAction
{
    use ExtractsPdfPayloadValuesTrait;

    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Filesystem $pdf,
        public readonly array $extracted,
        public readonly int $pdfIngestLogId,
        public readonly ?UserInterface $user = null,
        public readonly ?string $fromEmail = null,
    ) {
    }

    public function execute(): ?Bill
    {
        $total = $this->numeric($this->extracted['total'] ?? null);
        if ($total === null || $total <= 0) {
            Log::warning('Scribe.PdfIngest.ProposeBill skipped — no total in extraction', [
                'filesystem_id' => $this->pdf->getKey(),
                'pdf_ingest_log_id' => $this->pdfIngestLogId,
            ]);

            return null;
        }

        // Path 3 — vendor bill_number dedup. If the LLM extracted a bill_number and we already have
        // a non-voided Bill with the same number for this tenant, return the existing Bill instead
        // of creating a duplicate. The orchestrator marks the log as IGNORED_DUPLICATE.
        $billNumberCandidate = $this->trimOrDefault($this->extracted['bill_number'] ?? null, null);
        if ($billNumberCandidate !== null) {
            $existing = $this->findExistingBillByNumber($billNumberCandidate);
            if ($existing !== null) {
                return $existing;
            }
        }

        $expenseAccountId = $this->resolveDefaultExpenseAccountId();
        if ($expenseAccountId === null) {
            Log::error('Scribe.PdfIngest.ProposeBill skipped — no fallback expense account in COA', [
                'app_id' => $this->app->getId(),
                'company_id' => $this->company->getId(),
            ]);

            return null;
        }

        $billDate = $this->parseDate($this->extracted['issue_date'] ?? null) ?? Carbon::today();
        $dueDate = $this->parseDate($this->extracted['due_date'] ?? null);
        $currency = $this->trimOrDefault($this->extracted['currency'] ?? null, 'USD');
        $taxNative = $this->numeric($this->extracted['tax'] ?? null) ?? 0.0;
        $subtotal = $this->numeric($this->extracted['subtotal'] ?? null) ?? ($total - $taxNative);
        $notes = trim('Imported from PDF. ' . (string) ($this->extracted['notes'] ?? ''));
        $vendorDisplayName = $this->trimOrDefault(
            $this->extracted['vendor_name'] ?? null,
            $this->fromEmail !== null ? "Vendor ({$this->fromEmail})" : 'Unknown vendor',
        );

        $lines = $this->buildLines($expenseAccountId, $subtotal, $taxNative);

        $data = new BillData(
            app: $this->app,
            company: $this->company,
            vendor: null,                                              // operator resolves vendor during review
            lines: new DataCollection(BillLineData::class, $lines),
            currency: $currency,
            fx_rate_to_base: 1.0,
            bill_number: $this->trimOrDefault($this->extracted['bill_number'] ?? null, null),
            bill_date: $billDate,
            due_date: $dueDate,
            notes: $notes,
            tax_metadata: $this->extracted['tax_metadata'] ?? null,
            metadata: [
                'ingest' => [
                    'source' => 'parsed_pdf',
                    'pdf_filesystem_id' => (int) $this->pdf->getKey(),
                    'pdf_ingest_log_id' => $this->pdfIngestLogId,
                    'vendor_name' => $vendorDisplayName,
                    'vendor_tax_id' => $this->extracted['vendor_tax_id'] ?? null,
                    'vendor_email' => $this->extracted['vendor_email'] ?? $this->fromEmail,
                    'extracted' => $this->extracted,
                ],
            ],
            source: 'parsed_pdf',
            external_id: $this->pdf->uuid,
            pdf_ingest_log_id: $this->pdfIngestLogId,
        );

        $bill = new CreateBillAction(data: $data, user: $this->user)->execute();

        // Set the vendor display name into the snapshot up-front so the operator sees it in the list
        // (legal_name/tax_id/email stay null until ReceiveBill freezes the full PayeeInterface snapshot).
        $bill->vendor_display_name = $vendorDisplayName;
        $bill->vendor_legal_name = $this->trimOrDefault($this->extracted['vendor_name'] ?? null, null);
        $bill->vendor_tax_id = $this->trimOrDefault($this->extracted['vendor_tax_id'] ?? null, null);
        $bill->vendor_email = $this->trimOrDefault(
            $this->extracted['vendor_email'] ?? null,
            $this->fromEmail,
        );
        $bill->save();

        return $bill->refresh();
    }

    /**
     * @return array<int, BillLineData>
     */
    private function buildLines(int $expenseAccountId, float $subtotal, float $taxNative): array
    {
        $lineItems = $this->extracted['line_items'] ?? [];

        if (! is_array($lineItems) || $lineItems === []) {
            return [new BillLineData(
                description: (string) ($this->extracted['notes'] ?? 'Bill from PDF — itemize during review'),
                quantity: 1.0,
                unit_price_native: $subtotal,
                tax_amount_native: $taxNative,
                expense_account_id: $expenseAccountId,
            )];
        }

        $out = [];
        $sortOrder = 0;
        foreach ($lineItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $unitPrice = $this->numeric($item['unit_price'] ?? null) ?? 0.0;
            $qty = $this->numeric($item['qty'] ?? null) ?? 1.0;
            $lineTotal = $this->numeric($item['line_total'] ?? null) ?? ($qty * $unitPrice);
            if ($lineTotal <= 0) {
                continue;
            }
            $out[] = new BillLineData(
                description: (string) ($item['description'] ?? 'Line'),
                quantity: $qty,
                unit_price_native: $unitPrice,
                expense_account_id: $expenseAccountId,
                sort_order: $sortOrder++,
            );
        }

        // Roll header tax onto the first line if we generated lines from extraction
        if ($out !== [] && $taxNative > 0) {
            $first = $out[0];
            $out[0] = new BillLineData(
                description: $first->description,
                quantity: $first->quantity,
                unit_price_native: $first->unit_price_native,
                expense_account_id: $first->expense_account_id,
                tax_amount_native: $taxNative,
                sort_order: $first->sort_order,
            );
        }

        return $out;
    }

    /**
     * Path 3 — look up an existing non-voided Bill for this tenant with the same bill_number.
     * Voided bills are excluded (a void implies the user intentionally rejected the prior copy).
     */
    private function findExistingBillByNumber(string $billNumber): ?Bill
    {
        return Bill::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('bill_number', $billNumber)
            ->where('document_status', '!=', BillDocumentStatusEnum::VOIDED->value)
            ->where('is_deleted', false)
            ->orderBy('id')
            ->first();
    }
}
