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
use Kanvas\Scribe\Expenses\Actions\AttachExpenseReceiptAction;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\PdfIngest\Traits\ExtractsPdfPayloadValuesTrait;
use Spatie\LaravelData\DataCollection;

/**
 * Writes a DRAFT Expense from the LLM-extracted PDF payload, attaches the PDF as a receipt, and
 * returns the Expense (or null when extraction is unusable).
 *
 * The Expense lands with:
 *   - status=DRAFT (user reviews + approves through the normal Expenses dashboard flow)
 *   - source='parsed_pdf'
 *   - external_id=<filesystem_uuid> (idempotency-friendly cross-system reference)
 *   - paid_by inferred from the LLM's `payment_method_hint`; default COMPANY_CARD when unsure
 *   - notes prefixed with "Imported from PDF" + LLM notes
 *   - metadata.ingest = the raw LLM payload (preserved verbatim for audit / re-classification)
 *   - one ExpenseLine per line_item in extraction (or one summary line when LLM didn't break it down)
 *
 * Expense account: tries to pick a sensible default. If the LLM gave us a useful description, this
 * action does NOT try to be clever about matching expense accounts — uses the system default
 * (Travel & Meals as a generic catch-all) and lets the user reassign during review. Auto-classifying
 * to specific expense accounts is a follow-up PR (could even be an agent tool).
 */
class ProposeExpenseFromPdfAction
{
    use ExtractsPdfPayloadValuesTrait;

    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Filesystem $pdf,
        public readonly array $extracted,
        public readonly ?UserInterface $user = null,
        public readonly ?string $fromEmail = null,
    ) {
    }

    public function execute(): ?Expense
    {
        $total = $this->floatOrNull($this->extracted['total'] ?? null);
        if ($total === null || $total <= 0) {
            Log::warning('Scribe.PdfIngest.ProposeExpense skipped — no total in extraction', [
                'filesystem_id' => $this->pdf->getKey(),
            ]);

            return null;
        }

        $expenseAccountId = $this->resolveDefaultExpenseAccountId();
        if ($expenseAccountId === null) {
            Log::error('Scribe.PdfIngest.ProposeExpense skipped — no Travel & Meals fallback account in COA', [
                'app_id' => $this->app->getId(),
                'company_id' => $this->company->getId(),
            ]);

            return null;
        }

        $expenseDate = $this->parseIso($this->extracted['issue_date'] ?? null) ?? Carbon::today();
        $currency = $this->trimOrDefault($this->extracted['currency'] ?? null, 'USD');
        $paidBy = $this->mapPaymentMethodHint($this->extracted['payment_method_hint'] ?? null);
        $vendorDisplayName = $this->trimOrDefault(
            $this->extracted['vendor_name'] ?? null,
            $this->fromEmail !== null ? "Vendor ({$this->fromEmail})" : 'Unknown vendor',
        );
        $taxNative = $this->floatOrNull($this->extracted['tax'] ?? null) ?? 0.0;
        $subtotal = $this->floatOrNull($this->extracted['subtotal'] ?? null) ?? ($total - $taxNative);
        $notes = trim('Imported from PDF. ' . (string) ($this->extracted['notes'] ?? ''));

        $lines = $this->buildLines($expenseAccountId, $subtotal, $taxNative);

        $data = new ExpenseData(
            app: $this->app,
            company: $this->company,
            lines: new DataCollection(ExpenseLineData::class, $lines),
            expense_date: $expenseDate,
            currency: $currency,
            fx_rate_to_base: 1.0,
            paid_by: $paidBy,
            paid_by_users_id: $paidBy === ExpensePaidByEnum::EMPLOYEE_PERSONAL
                ? $this->user?->getId()
                : null,
            notes: $notes,
            tax_metadata: $this->extracted['tax_metadata'] ?? null,
            metadata: [
                'ingest' => [
                    'source' => 'parsed_pdf',
                    'pdf_filesystem_id' => (int) $this->pdf->getKey(),
                    'vendor_name' => $vendorDisplayName,
                    'vendor_tax_id' => $this->extracted['vendor_tax_id'] ?? null,
                    'vendor_email' => $this->extracted['vendor_email'] ?? $this->fromEmail,
                    'extracted' => $this->extracted,
                ],
            ],
            source: 'parsed_pdf',
            external_id: $this->pdf->uuid,
        );

        $expense = new CreateExpenseAction(data: $data, user: $this->user)->execute();

        // Auto-resolve/create the vendor Guild Org from the extracted payload. Drafts ship with the
        // vendor link already set so the operator doesn't have to pick on approve. Duplicates are
        // cleaned up later via MergeOrganizationsAction.
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
                $expense->vendor_organization_id = (int) $vendor->id;
                $expense->save();
            }
        }

        $this->freezeVendorSnapshot($expense, $vendorDisplayName);

        new AttachExpenseReceiptAction(
            expense: $expense,
            filesystem: $this->pdf,
            user: $this->user,
            metadata: [
                'attached_via' => 'pdf_ingest',
                'classification_confidence' => $this->extracted['_confidence'] ?? null,
            ],
        )->execute();

        return $expense->refresh();
    }

    /**
     * Vendor display name + email are extracted but the snapshot fields on Expense are only frozen
     * on Approve in the normal flow. For PDF-ingested expenses we set them now so the user sees the
     * vendor name in the Expenses list even before submitting for approval.
     */
    private function freezeVendorSnapshot(Expense $expense, string $displayName): void
    {
        $expense->vendor_display_name = $displayName;
        $expense->vendor_legal_name = $this->trimOrDefault($this->extracted['vendor_name'] ?? null, null);
        $expense->vendor_tax_id = $this->trimOrDefault($this->extracted['vendor_tax_id'] ?? null, null);
        $expense->vendor_email = $this->trimOrDefault(
            $this->extracted['vendor_email'] ?? null,
            $this->fromEmail,
        );
        $expense->save();
    }

    /**
     * @return array<int, ExpenseLineData>
     */
    private function buildLines(int $expenseAccountId, float $subtotal, float $taxNative): array
    {
        $lineItems = $this->extracted['line_items'] ?? [];

        if (! is_array($lineItems) || $lineItems === []) {
            return [new ExpenseLineData(
                description: (string) ($this->extracted['notes'] ?? 'PDF receipt — itemize during review'),
                amount_native: $subtotal,
                expense_account_id: $expenseAccountId,
                tax_amount_native: $taxNative,
            )];
        }

        $out = [];
        $sortOrder = 0;
        foreach ($lineItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $lineTotal = $this->floatOrNull($item['line_total'] ?? null)
                ?? (($this->floatOrNull($item['qty'] ?? null) ?? 1) * ($this->floatOrNull($item['unit_price'] ?? null) ?? 0));
            if ($lineTotal <= 0) {
                continue;
            }
            $out[] = new ExpenseLineData(
                description: (string) ($item['description'] ?? 'Line'),
                amount_native: $lineTotal,
                expense_account_id: $expenseAccountId,
                tax_amount_native: 0.0,                                     // tax goes on the first line via summary mode below
                sort_order: $sortOrder++,
            );
        }

        // Roll header tax onto the first line if we generated any lines from the LLM extraction.
        if ($out !== [] && $taxNative > 0) {
            $first = $out[0];
            $out[0] = new ExpenseLineData(
                description: $first->description,
                amount_native: $first->amount_native,
                expense_account_id: $first->expense_account_id,
                tax_amount_native: $taxNative,
                sort_order: $first->sort_order,
            );
        }

        return $out;
    }

    private function mapPaymentMethodHint(?string $hint): ExpensePaidByEnum
    {
        return match ($hint) {
            'credit_card' => ExpensePaidByEnum::COMPANY_CARD,
            'bank_transfer' => ExpensePaidByEnum::COMPANY_BANK_TRANSFER,
            'cash' => ExpensePaidByEnum::COMPANY_CASH,
            'employee_personal' => ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            default => ExpensePaidByEnum::COMPANY_CARD,
        };
    }
}
