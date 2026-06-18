<?php

declare(strict_types=1);

namespace Tests\Scribe\E2E;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Actions\MarkBillPaidAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Bills\Services\BillJournalEntryComposerService;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\Reports\Repositories\AccountActivityRepository;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\PdfIngest\Stubs\FakePdfContentHasher;
use Tests\Scribe\ScribeTestCase;

/**
 * Flow 4 — Full PDF-to-Bill AP cycle: ingest → draft Bill → Receive → Pay.
 *
 *   1. ProcessAccountingPdfAction with VENDOR_INVOICE classification
 *   2. → ProposeBillFromPdfAction creates draft Bill (vendor reference NULL until operator resolves)
 *   3. Operator picks a vendor (stub Payee) and calls ReceiveBillAction → posts AP JE
 *      (DR Expense + DR Input Tax / CR AP)
 *   4. Create BillPaymentAllocation + compose payment JE → MarkBillPaidAction flips to PAID
 *
 * What it proves:
 *   - The whole PDF → Bill → AP → Payment chain works without manual entry
 *   - 2 POSTED JEs land balanced (Receive + Payment)
 *   - GL post-payment: Expense=+subtotal, Input Tax Receivable=+tax, AP=0 (cleared), Cash=-total
 *   - PdfIngestLog correctly links to Bill via linked_entity_*
 */
class PdfToBillFlowTest extends ScribeTestCase
{
    public function test_full_pdf_to_bill_to_payment_cycle(): void
    {
        $pdf = $this->createFilesystemRow();

        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
            confidence: 0.93,
            reasoning: 'AWS invoice dated 2026-06-15, due 2026-07-15.',
            extracted: [
                'vendor_name' => 'Amazon Web Services',
                'vendor_email' => 'no-reply@amazonaws.com',
                'bill_number' => 'AWS-2026-06-001',
                'issue_date' => '2026-06-15',
                'due_date' => '2026-07-15',
                'currency' => 'USD',
                'subtotal' => 1000.0,
                'tax' => 80.0,
                'total' => 1080.0,
                'line_items' => [
                    ['description' => 'EC2 + S3', 'qty' => 1, 'unit_price' => 1000.0, 'line_total' => 1000.0],
                ],
            ],
        ));

        // ── Step 1+2 — ingest PDF, draft Bill lands ──
        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'aws-monthly-invoice-2026-06',
                fromEmail: 'no-reply@amazonaws.com',
                subject: 'Your AWS Invoice',
            ),
            user: static::$cachedUser,
            classifier: $classifier,
            hasher: new FakePdfContentHasher(),
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame('bill', $log->linked_entity_type);

        /** @var Bill $bill */
        $bill = Bill::query()->where('id', $log->linked_entity_id)->firstOrFail();
        $this->assertSame(BillDocumentStatusEnum::DRAFT, $bill->document_status);
        $this->assertSame('parsed_pdf', $bill->source);
        $this->assertSame((int) $log->id, (int) $bill->pdf_ingest_log_id);
        $this->assertSame('Amazon Web Services', $bill->vendor_display_name);
        $this->assertNull(
            $bill->vendor_billable_type,
            'Vendor reference stays NULL on draft until operator resolves it.',
        );
        $this->assertEqualsWithDelta(1080.0, (float) $bill->total_native, 0.005);

        // ── Step 3 — operator resolves vendor (stub Payee) and receives the bill ──
        $vendor = $this->seedTestOrganization('Amazon Web Services');
        $received = new ReceiveBillAction(
            bill: $bill,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $received->document_status);
        $this->assertSame((int) $vendor->id, (int) $received->vendor_organization_id, 'Vendor FK frozen at receive.');

        $receiveJes = $this->postedJesFor('bill', (int) $received->id);
        $this->assertCount(1, $receiveJes);
        $this->assertJeBalanced($receiveJes->first());

        // ── Step 4 — pay the bill (allocation + composer + Mark) ──
        $allocation = new BillPaymentAllocation();
        $allocation->apps_id = (int) $received->apps_id;
        $allocation->companies_id = (int) $received->companies_id;
        $allocation->bill_id = (int) $received->id;
        $allocation->source_type = 'souk_payment';
        $allocation->status = AllocationStatusEnum::ACTIVE->value;
        $allocation->amount_native = 1080.0;
        $allocation->amount_base = 1080.0;
        $allocation->currency = 'USD';
        $allocation->fx_rate_to_base = 1.0;
        $allocation->allocated_at = Carbon::parse('2026-06-25');
        $allocation->source = 'kanvas';
        $allocation->save();

        $paymentJeData = new BillJournalEntryComposerService()->composePayment(
            bill: $received,
            allocation: $allocation,
        );
        new PostJournalEntryAction(
            data: $paymentJeData,
            postedByUser: static::$cachedUser,
        )->execute();

        $paid = new MarkBillPaidAction(
            bill: $received,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BillDocumentStatusEnum::PAID, $paid->document_status);
        $this->assertEqualsWithDelta(0.0, (float) $paid->balance_due_native, 0.005);

        // ── Cross-cutting: 2 POSTED JEs, both balanced ──
        // Receive JE uses source_type='bill'; payment JE uses source_type='bill_payment'.
        $billJes = $this->postedJesFor('bill', (int) $paid->id);
        $paymentJes = JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source_type', 'bill_payment')
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->get();
        $this->assertCount(1, $billJes, 'Receive JE on source_type=bill.');
        $this->assertCount(1, $paymentJes, 'Payment JE on source_type=bill_payment.');
        foreach ($billJes->merge($paymentJes) as $je) {
            $this->assertJeBalanced($je);
        }

        // ── Cross-cutting: GL balances ──
        $accountIds = $this->accountIdsByKey([
            AccountSubTypeEnum::TRAVEL_AND_MEALS,
            AccountSubTypeEnum::INPUT_TAX_RECEIVABLE,
            AccountSubTypeEnum::ACCOUNTS_PAYABLE,
            AccountSubTypeEnum::CASH_CHECKING,
        ]);
        $balances = new AccountActivityRepository()->getBalancesAsOf(
            app: $this->kanvasApp,
            company: $this->company,
            accountIds: array_values($accountIds),
            asOfDate: Carbon::parse('2026-06-30'),
        );

        $this->assertEqualsWithDelta(
            1000.0,
            $balances[$accountIds[AccountSubTypeEnum::TRAVEL_AND_MEALS->value]],
            0.005,
            'Expense booked at subtotal.',
        );
        $this->assertEqualsWithDelta(
            80.0,
            $balances[$accountIds[AccountSubTypeEnum::INPUT_TAX_RECEIVABLE->value]],
            0.005,
            'Input tax receivable booked.',
        );
        $this->assertEqualsWithDelta(
            0.0,
            $balances[$accountIds[AccountSubTypeEnum::ACCOUNTS_PAYABLE->value]],
            0.005,
            'AP cleared after payment.',
        );
        $this->assertEqualsWithDelta(
            -1080.0,
            $balances[$accountIds[AccountSubTypeEnum::CASH_CHECKING->value]],
            0.005,
            'Cash paid out, full total including tax.',
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, JournalEntry>
     */
    private function postedJesFor(string $sourceType, int $sourceId)
    {
        return JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->whereNull('is_reversal_of')
            ->orderBy('id')
            ->get();
    }

    private function assertJeBalanced(JournalEntry $je): void
    {
        $je->load('lines');
        $debit = (float) $je->lines->sum('debit_base');
        $credit = (float) $je->lines->sum('credit_base');
        $this->assertEqualsWithDelta($debit, $credit, 0.005, "JE {$je->id} not balanced.");
    }

    /**
     * @param  list<AccountSubTypeEnum>  $subTypes
     * @return array<string, int>  keyed by sub-type value
     */
    private function accountIdsByKey(array $subTypes): array
    {
        $out = [];
        foreach ($subTypes as $sub) {
            $out[$sub->value] = $this->accountIdBySubType($sub);
        }

        return $out;
    }
}
