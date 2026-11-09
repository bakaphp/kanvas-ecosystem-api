<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\AllocateBillPaymentAction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\DataTransferObject\ExpenseAccountCoding;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Enums\PaymentStatusHintEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Services\ExpenseAccountCodingService;
use Kanvas\Scribe\Bills\Services\VendorByTaxIdResolverService;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\PdfIngest\Traits\ExtractsPdfPayloadValuesTrait;
use Kanvas\Scribe\Regional\DataTransferObject\TaxpayerLookup;
use Kanvas\Scribe\Regional\Services\TaxpayerLookupService;
use Kanvas\Scribe\Regional\Validators\DominicanRepublic\NcfValidator;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * Turns an LLM-extracted vendor invoice into a Bill, ending in the same state the AP agent's own intake
 * does: vendor resolved, lines coded, submitted for approval (which notifies the approver).
 *
 *   - Vendor: resolved/created from the extracted name, email and tax id. Needs an acting user, which the
 *     inbox job passes (the AP agent's user, the company AI user, or the receiver's owner).
 *   - GL: the vendor's default expense account, its coded history, an item named on the lines, then an AI
 *     suggestion whose confidence rides on the bill for the approver (ExpenseAccountCodingService). Never a
 *     catch-all guess presented as a coding.
 *   - Dominican company: the supplier is found by RNC first and its RNC checked at DGII; the NCF is checked
 *     for format and type into `regional_compliance.do_ncf`. A missing or wrong NCF holds the bill for review.
 *   - When the vendor or the GL can't be resolved the bill stays DRAFT, its lines on a placeholder
 *     expense account, with the reason in `metadata.ingest.review_reason` for whoever reviews it.
 *   - source='parsed_pdf', pdf_ingest_log_id and external_id=<filesystem uuid> link it back to the PDF.
 *
 * Returns null when extraction is unusable (no total, no expense account in COA). Returns the EXISTING
 * bill on a bill_number duplicate — the caller tells the two apart by pdf_ingest_log_id.
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

    /** `regional_compliance` key for the supplier NCF + RNC check on a Dominican company's bill. */
    public const string DO_NCF = 'do_ncf';

    /** `metadata.ingest.review_code`: what the person (or the AP agent) has to fix. */
    public const string REVIEW_VENDOR = 'vendor_unresolved';
    public const string REVIEW_GL = 'gl_unresolved';
    public const string REVIEW_NCF = 'ncf_invalid';
    public const string REVIEW_RNC = 'rnc_not_registered';

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

        $vendorDisplayName = $this->trimOrDefault(
            $this->extracted['vendor_name'] ?? null,
            $this->fromEmail !== null ? "Vendor ({$this->fromEmail})" : 'Unknown vendor',
        );

        $vendor = $this->resolveVendor($vendorDisplayName);
        $coding = new ExpenseAccountCodingService()->code(
            $this->app,
            $this->company,
            $vendor,
            $vendorDisplayName,
            $this->lineDescriptions(),
        );
        $compliance = $this->dominicanCompliance($vendor);
        [$reviewCode, $reviewReason] = $this->review(
            $vendor,
            $coding,
            $vendorDisplayName,
            $compliance
        );
        $codedAccountId = $coding?->accountId;

        $expenseAccountId = $codedAccountId ?? $this->resolveDefaultExpenseAccountId();
        if ($expenseAccountId === null) {
            Log::error('Scribe.PdfIngest.ProposeBill skipped — no expense account in COA', [
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

        $lines = $this->buildLines($expenseAccountId, $subtotal, $taxNative);

        $data = new BillData(
            app: $this->app,
            company: $this->company,
            vendor: $vendor,
            lines: new DataCollection(BillLineData::class, $lines),
            currency: $currency,
            fx_rate_to_base: 1.0,
            bill_number: $billNumberCandidate,
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
                    'gl_source' => $this->glSource($coding),
                    'gl_coding' => $coding !== null ? [
                        'source' => $coding->source,
                        'confidence' => $coding->confidence,
                        'reasoning' => $coding->reasoning,
                    ] : null,
                    'review_reason' => $reviewReason,
                    'review_code' => $reviewCode,
                    'extracted' => $this->extracted,
                ],
            ],
            source: 'parsed_pdf',
            external_id: $this->pdf->uuid,
            pdf_ingest_log_id: $this->pdfIngestLogId,
        );

        $bill = new CreateBillAction(data: $data, user: $this->user)->execute();

        // The snapshot shows the extracted spelling up-front; ReceiveBill re-freezes it from the linked
        // Guild Org at the moment of receipt.
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

        if ($compliance !== null) {
            $bill->regional_compliance = [...(array) $bill->regional_compliance, self::DO_NCF => $compliance];
        }

        // The approver is the check on an AI coding, so the approval carries how sure it was.
        if ($coding !== null && $coding->isSuggestion()) {
            $bill->appendInternalNote(sprintf(
                'Expense account suggested by AI (confidence %d%%): %s Check the coding before approving.',
                (int) round($coding->confidence * 100),
                (string) $coding->reasoning,
            ));
        }

        $bill->save();
        $bill->refresh();

        if ($reviewReason !== null) {
            return $bill;
        }

        $this->maybeAutoAdvanceToPaid($bill, $vendor);
        $bill->refresh();

        if ($bill->document_status === BillDocumentStatusEnum::DRAFT) {
            $this->submitForApproval($bill);
        }

        return $bill->refresh();
    }

    /**
     * The tax id first for a Dominican supplier: it is the legal identity, a name match is not. A vendor
     * found or created without one gets the invoice's.
     */
    private function resolveVendor(string $vendorDisplayName): ?Organization
    {
        if ($this->user === null) {
            return null;
        }

        return new VendorByTaxIdResolverService()->resolveOrCreate(
            $this->app,
            $this->company,
            $this->user,
            $this->dominicanTaxId(),
            [
                'vendor_name' => $this->extracted['vendor_name'] ?? $vendorDisplayName,
                'vendor_email' => $this->extracted['vendor_email'] ?? $this->fromEmail,
                'vendor_tax_id' => $this->extracted['vendor_tax_id'] ?? null,
            ],
        );
    }

    /**
     * For a Dominican company: the bill's NCF checked for format and type, and the supplier's RNC checked
     * at DGII when the company has an e-CF provider to ask. Null for every other country.
     *
     * @return array<string, mixed>|null
     */
    private function dominicanCompliance(?Organization $vendor): ?array
    {
        if (! $this->isDominicanCompany()) {
            return null;
        }

        $ncf = new NcfValidator()->validate($this->trimOrDefault($this->extracted['ncf'] ?? null, null));
        $taxId = $this->dominicanTaxId() ?? ($vendor !== null ? Str::digitsOnly($vendor->getPayeeTaxId()) : null);
        $rnc = $taxId !== null && $taxId !== '' && $this->company instanceof Companies && $this->app instanceof Apps
            ? new TaxpayerLookupService()->lookup($this->app, $this->company, $taxId)
            : null;

        return $ncf->toArray() + [
            'vendor_rnc' => $taxId !== '' ? $taxId : null,
            'rnc_status' => $rnc?->status,
            'rnc_legal_name' => $rnc?->legalName,
            'checked_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed>|null $compliance
     * @return array{0: ?string, 1: ?string} review code and reason, both null when the bill can go to approval
     */
    private function review(
        ?Organization $vendor,
        ?ExpenseAccountCoding $coding,
        string $vendorDisplayName,
        ?array $compliance,
    ): array {
        return match (true) {
            $this->user === null => [self::REVIEW_VENDOR, 'No acting user was available to resolve the vendor.'],
            $vendor === null => [self::REVIEW_VENDOR, "The vendor \"{$vendorDisplayName}\" could not be matched or created."],
            $compliance !== null && ! $compliance['valid'] => [self::REVIEW_NCF, (string) $compliance['reason']],
            ($compliance['rnc_status'] ?? null) === TaxpayerLookup::NOT_REGISTERED => [
                self::REVIEW_RNC,
                "DGII has no taxpayer registered under RNC {$compliance['vendor_rnc']}.",
            ],
            $coding === null => [self::REVIEW_GL, "Vendor \"{$vendor->name}\" has no default expense account and no coded "
                . 'bills to learn one from; the lines sit on a placeholder account until someone codes them.'],
            default => [null, null],
        };
    }

    private function glSource(?ExpenseAccountCoding $coding): string
    {
        return match ($coding?->source) {
            null => 'placeholder',
            ExpenseAccountCoding::VENDOR, ExpenseAccountCoding::HISTORY => 'vendor',
            default => $coding->source,
        };
    }

    /**
     * @return list<string>
     */
    private function lineDescriptions(): array
    {
        $descriptions = [];
        foreach ((array) ($this->extracted['line_items'] ?? []) as $item) {
            $description = is_array($item) ? trim((string) ($item['description'] ?? '')) : '';
            if ($description !== '') {
                $descriptions[] = $description;
            }
        }

        $notes = trim((string) ($this->extracted['notes'] ?? ''));
        if ($descriptions === [] && $notes !== '') {
            $descriptions[] = $notes;
        }

        return $descriptions;
    }

    /**
     * Paper NCFs are a Dominican rule (Regional/Validators/Dr). A company is Dominican by its e-invoicing regime,
     * so one connected to a DR provider with no country set still gets the checks.
     */
    private function isDominicanCompany(): bool
    {
        return $this->company instanceof Companies
            && FiscalRegimes::forCompany($this->company)?->country() === DrFiscalRegime::COUNTRY;
    }

    private function dominicanTaxId(): ?string
    {
        if (! $this->isDominicanCompany()) {
            return null;
        }

        $taxId = Str::digitsOnly((string) ($this->extracted['vendor_tax_id'] ?? ''));

        return DrFiscalRegime::isTaxId($taxId) ? $taxId : null;
    }

    /**
     * Best-effort: a bill that could not be submitted stays a reviewable DRAFT, never a lost invoice.
     */
    private function submitForApproval(Bill $bill): void
    {
        try {
            new SubmitBillForApprovalAction($bill, $this->user)->execute();
        } catch (Throwable $e) {
            report($e);

            $metadata = $bill->metadata ?? [];
            $metadata['ingest']['review_reason'] = 'Submitting for approval failed: ' . $e->getMessage();
            $bill->metadata = $metadata;
            $bill->save();
        }
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
                method: PaymentMethodEnum::PDF_INGEST_AUTO,
                user: $this->user,
                source: 'kanvas',
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
