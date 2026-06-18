<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Organizations\Actions\ResolveOrCreateOrganizationFromVendorPayloadAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\AllocateBillPaymentAction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Enums\PaymentStatusHintEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\PdfIngest\Traits\ExtractsPdfPayloadValuesTrait;
use Spatie\LaravelData\DataCollection;
use Throwable;

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

    /**
     * Minimum LLM classification confidence to auto-advance a PDF-ingested Bill from DRAFT all the way
     * to PAID when payment_status_hint='paid'. Below this, the Bill stays DRAFT and the operator
     * decides via the UI suggestion. Anthropic-style clean receipts typically come in at ≥ 0.9.
     */
    public const AUTO_ADVANCE_CONFIDENCE_THRESHOLD = 0.85;

    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Filesystem $pdf,
        public readonly array $extracted,
        public readonly int $pdfIngestLogId,
        public readonly ?UserInterface $user = null,
        public readonly ?string $fromEmail = null,
        public readonly ?float $confidence = null,
    ) {
    }

    public function execute(): ?Bill
    {
        $total = $this->floatOrNull($this->extracted['total'] ?? null);
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

        $billDate = $this->parseIso($this->extracted['issue_date'] ?? null) ?? Carbon::today();
        $dueDate = $this->parseIso($this->extracted['due_date'] ?? null);
        $currency = $this->trimOrDefault($this->extracted['currency'] ?? null, 'USD');
        $taxNative = $this->floatOrNull($this->extracted['tax'] ?? null) ?? 0.0;
        $subtotal = $this->floatOrNull($this->extracted['subtotal'] ?? null) ?? ($total - $taxNative);
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

        // Auto-resolve/create the vendor Guild Org from the extracted payload + email hint. Drafts
        // ship with vendor_organization_id already set so the operator doesn't have to pick during
        // approval. Duplicates are cleaned up later via MergeOrganizationsAction.
        $vendor = null;
        if ($this->user !== null) {
            $vendor = new ResolveOrCreateOrganizationFromVendorPayloadAction(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                payload: [
                    'vendor_name' => $this->extracted['vendor_name'] ?? $vendorDisplayName,
                    'vendor_email' => $this->extracted['vendor_email'] ?? $this->fromEmail,
                    'vendor_tax_id' => $this->extracted['vendor_tax_id'] ?? null,
                ],
            )->execute();
            if ($vendor !== null) {
                $bill->vendor_organization_id = (int) $vendor->id;
            }
        }

        // Set the vendor display name into the snapshot up-front so the operator sees it in the list
        // (legal_name/tax_id/email also populated from the extraction; ReceiveBill re-freezes them
        // from the linked Guild Org at the moment of receipt).
        $bill->vendor_display_name = $vendorDisplayName;
        $bill->vendor_legal_name = $this->trimOrDefault($this->extracted['vendor_name'] ?? null, null);
        $bill->vendor_tax_id = $this->trimOrDefault($this->extracted['vendor_tax_id'] ?? null, null);
        $bill->vendor_email = $this->trimOrDefault(
            $this->extracted['vendor_email'] ?? null,
            $this->fromEmail,
        );

        // LLM payment-status hint. The operator UI uses this to surface a "Mark as already paid?"
        // suggestion on PDF-ingested drafts. Defaults to UNKNOWN when LLM didn't emit a value.
        $hint = $this->trimOrDefault($this->extracted['payment_status_hint'] ?? null, null);
        if ($hint !== null) {
            $parsed = PaymentStatusHintEnum::tryFrom(strtolower($hint));
            if ($parsed !== null) {
                $bill->payment_status_hint = $parsed;
            }
        }

        $bill->save();
        $bill->refresh();

        $this->maybeAutoAdvanceToPaid($bill, $vendor ?? null);

        return $bill->refresh();
    }

    /**
     * When the LLM said "this PDF is already paid" with high confidence, run the full lifecycle:
     * DRAFT → RECEIVED (posts AP JE) → PAID (manual allocation posts Cash JE).
     *
     * Skip silently when any precondition is missing — bill stays DRAFT and the UI hint guides the
     * operator. Failures inside the lifecycle bubble up logged but don't fail the whole ingest
     * (the Bill is still usable; operator can advance manually).
     */
    private function maybeAutoAdvanceToPaid(Bill $bill, ?Organization $vendor): void
    {
        if ($bill->payment_status_hint !== PaymentStatusHintEnum::PAID) {
            return;
        }
        if ($this->confidence === null || $this->confidence < self::AUTO_ADVANCE_CONFIDENCE_THRESHOLD) {
            return;
        }
        if ($vendor === null) {
            // Vendor resolution failed earlier — we'd have nothing to freeze into the snapshot.
            return;
        }
        if ($this->user === null) {
            return;
        }

        try {
            new ReceiveBillAction(
                bill: $bill,
                vendor: $vendor,
                user: $this->user,
            )->execute();

            new AllocateBillPaymentAction(
                bill: $bill->refresh(),
                amountNative: (float) $bill->total_native,
                user: $this->user,
                source: 'manual',
                metadata: [
                    'origin' => 'pdf_ingest_auto',
                    'pdf_ingest_log_id' => $this->pdfIngestLogId,
                    'confidence' => $this->confidence,
                ],
            )->execute();
        } catch (Throwable $e) {
            // Auto-advance is best-effort. Bill stays in whatever state it reached; operator finishes manually.
            Log::warning('Scribe.PdfIngest.ProposeBill auto-advance to PAID failed', [
                'bill_id' => $bill->id,
                'confidence' => $this->confidence,
                'exception' => $e->getMessage(),
            ]);
        }
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
            $unitPrice = $this->floatOrNull($item['unit_price'] ?? null) ?? 0.0;
            $qty = $this->floatOrNull($item['qty'] ?? null) ?? 1.0;
            $lineTotal = $this->floatOrNull($item['line_total'] ?? null) ?? ($qty * $unitPrice);
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
