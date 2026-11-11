<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Enums\VendorCustomFieldEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\PdfIngest\Stubs\FakePdfContentHasher;
use Tests\Scribe\ScribeTestCase;

/**
 * The inbox has to end where the AP agent's own intake ends: vendor resolved, coded from the vendor's
 * default or history, submitted for approval.
 */
class InboxBillIntakeTest extends ScribeTestCase
{
    public function test_a_vendor_with_a_default_account_gets_a_coded_bill_submitted_for_approval(): void
    {
        $account = $this->accountBySubType(AccountSubTypeEnum::SOFTWARE_SUBSCRIPTIONS);
        $vendor = $this->vendorWithEmail('Default GL Vendor');
        $vendor->set(VendorCustomFieldEnum::DEFAULT_EXPENSE_ACCOUNT->value, $account->account_number);

        [$log, $bill] = $this->ingest($vendor->email, static::$cachedUser);

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame(BillDocumentStatusEnum::PENDING_APPROVAL, $bill->document_status);
        $this->assertSame($vendor->getId(), (int) $bill->vendor_organization_id);
        $this->assertSame([$account->getId()], $bill->lines()->pluck('expense_account_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertSame('bill_created', $this->outcomeEvent($log)->payload['outcome']);
    }

    public function test_the_account_the_vendors_coded_bills_used_most_is_reused(): void
    {
        $account = $this->accountBySubType(AccountSubTypeEnum::SOFTWARE_SUBSCRIPTIONS);
        $vendor = $this->vendorWithEmail('History Vendor');
        $this->codedBill($vendor, $account);

        [$log, $bill] = $this->ingest($vendor->email, static::$cachedUser);

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame((int) $account->getId(), (int) $bill->lines()->first()->expense_account_id);
        $this->assertSame('vendor', $bill->metadata['ingest']['gl_source']);
    }

    public function test_a_vendor_with_no_default_or_history_stays_draft_with_the_reason(): void
    {
        $vendor = $this->vendorWithEmail('Unknown GL Vendor');

        [$log, $bill] = $this->ingest($vendor->email, static::$cachedUser);

        $this->assertSame(PdfIngestStatusEnum::NEEDS_REVIEW, $log->status);
        $this->assertSame(BillDocumentStatusEnum::DRAFT, $bill->document_status);
        $this->assertNull($bill->pendingApproval());
        $this->assertStringContainsString('no default expense account', (string) $log->rejected_reason);
        $this->assertSame($log->rejected_reason, $bill->metadata['ingest']['review_reason']);
        $this->assertSame('needs_review', $this->outcomeEvent($log)->payload['outcome']);
    }

    public function test_without_an_acting_user_the_vendor_cannot_resolve_and_the_bill_waits_for_review(): void
    {
        [$log, $bill] = $this->ingest('nobody-' . uniqid() . '@vendor.test', null);

        $this->assertSame(PdfIngestStatusEnum::NEEDS_REVIEW, $log->status);
        $this->assertNull($bill->vendor_organization_id);
        $this->assertStringContainsString('No acting user', (string) $log->rejected_reason);
    }

    public function test_a_vendor_invoice_number_already_billed_is_reported_as_a_duplicate(): void
    {
        $vendor = $this->vendorWithEmail('Duplicate Vendor');
        $number = 'DUP-' . uniqid();

        [$first] = $this->ingest($vendor->email, static::$cachedUser, $number);
        [$second] = $this->ingest($vendor->email, static::$cachedUser, $number);

        $this->assertSame(PdfIngestStatusEnum::IGNORED_DUPLICATE, $second->status);
        $this->assertSame($first->linked_entity_id, $second->linked_entity_id);
        $this->assertSame('duplicate', $this->outcomeEvent($second)->payload['outcome']);
    }

    /**
     * @return array{0: PdfIngestLog, 1: Bill}
     */
    private function ingest(string $vendorEmail, ?Users $user, ?string $billNumber = null): array
    {
        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $this->createFilesystemRow(),
                messageId: 'inbox-intake-' . uniqid(),
                fromEmail: $vendorEmail,
            ),
            user: $user,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
                confidence: 0.7,
                extracted: [
                    'vendor_name' => 'Vendor ' . $vendorEmail,
                    'vendor_email' => $vendorEmail,
                    'bill_number' => $billNumber ?? 'INV-' . uniqid(),
                    'currency' => 'USD',
                    'subtotal' => 120.0,
                    'tax' => 0.0,
                    'total' => 120.0,
                ],
            )),
            hasher: new FakePdfContentHasher(),
        )->execute();

        /** @var Bill $bill */
        $bill = Bill::query()->findOrFail($log->linked_entity_id);

        return [$log, $bill];
    }

    private function vendorWithEmail(string $name): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name . ' ' . uniqid(),
            'email' => 'billing-' . uniqid() . '@vendor.test',
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function codedBill(Organization $vendor, Account $account): void
    {
        $bill = new CreateBillAction(
            new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Seats',
                        quantity: 1,
                        unit_price_native: 50.0,
                        expense_account_id: (int) $account->getId(),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-10'),
            ),
            static::$cachedUser,
        )->execute();

        new SubmitBillForApprovalAction($bill, static::$cachedUser)->execute();
    }

    private function accountBySubType(AccountSubTypeEnum $subType): Account
    {
        /** @var Account $account */
        $account = Account::query()->findOrFail($this->accountIdBySubType($subType));

        return $account;
    }

    private function outcomeEvent(PdfIngestLog $log): Event
    {
        /** @var Event $event */
        $event = Event::query()
            ->where('event_type', 'scribe.document.ingested')
            ->where('source_entity_type', Filesystem::class)
            ->where('source_entity_id', $log->filesystem_id)
            ->latest('id')
            ->firstOrFail();

        return $event;
    }
}
