<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SubmitApBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\UpdateApBillTool;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\DataTransferObject\BillTaxLine as BillTaxLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillLine;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/** The AP agent corrects a draft the inbox left for review and sends it on, leaving amounts and vendor alone. */
class ApBillEditAndResubmitToolsTest extends ScribeTestCase
{
    public function test_update_changes_the_coding_number_and_dates_but_keeps_amounts_and_tax(): void
    {
        $bill = $this->draftBill('Edit Vendor LLC', 'EDT-001');
        $officeAccountId = $this->accountIdBySubType(AccountSubTypeEnum::OFFICE_SUPPLIES);

        $result = $this->updateTool()->__invoke(
            bill_id: $bill->getId(),
            gl_account_number: $this->accountCode(AccountSubTypeEnum::OFFICE_SUPPLIES),
            invoice_number: 'EDT-001-FIXED',
            invoice_date: '2026-10-01',
            due_date: '2026-10-31',
            notes: 'Corrected by AP',
        );

        $this->assertTrue($result['updated'], json_encode($result));
        $this->assertSame('draft', $result['document_status']);

        $fresh = Bill::getById($bill->getId());
        $this->assertSame('EDT-001-FIXED', $fresh->bill_number);
        $this->assertSame('2026-10-01', $fresh->bill_date->toDateString());
        $this->assertSame('2026-10-31', $fresh->due_date->toDateString());
        $this->assertSame('Corrected by AP', $fresh->notes);
        $this->assertSame(BillDocumentStatusEnum::DRAFT, $fresh->document_status);
        $this->assertSame((float) $bill->total_native, (float) $fresh->total_native);
        $this->assertSame((int) $bill->vendor_organization_id, (int) $fresh->vendor_organization_id);
        $this->assertCount(1, $fresh->taxLines);

        $lines = $fresh->lines;
        $this->assertCount(2, $lines);
        $accountIds = $lines->pluck('expense_account_id')->map(intval(...))->unique()->all();
        $this->assertSame([$officeAccountId], $accountIds);
        $this->assertSame([100.0, 50.0], $lines->pluck('unit_price_native')->map(floatval(...))->all());
    }

    public function test_update_keeps_taxed_amounts_the_inbox_metadata_and_an_uncoded_line(): void
    {
        $bill = $this->draftBill('Round Trip LLC', 'EDT-006');
        $firstLine = $bill->lines->first();
        BillLine::query()->whereKey($firstLine->getKey())->update([
            'tax_amount_native' => 18.0,
            'discount_amount_native' => 5.0,
            'line_total_native' => 113.0,
            'expense_account_id' => null,
        ]);
        $bill->metadata = ['ingest' => ['review_reason' => 'No expense account could be coded.']];
        $bill->total_native = 163.0;
        $bill->save();

        $result = $this->updateTool()->__invoke(bill_id: $bill->getId(), due_date: '2026-12-15');

        $this->assertTrue($result['updated'], json_encode($result));
        $this->assertSame('ok', $result['outcome']);
        $this->assertSame('No expense account could be coded.', $result['inbox_review_reason']);

        $fresh = Bill::getById($bill->getId());
        $this->assertSame(163.0, (float) $fresh->total_native);
        $this->assertSame(18.0, (float) $fresh->tax_native);
        $this->assertSame(5.0, (float) $fresh->discount_native);
        $this->assertSame('No expense account could be coded.', $fresh->inboxReviewReason());
        $this->assertNull($fresh->lines->first()->expense_account_id);
    }

    public function test_update_sets_a_subaccount_on_every_line_and_says_when_it_created_it(): void
    {
        $bill = $this->draftBill('Subaccount Vendor LLC', 'EDT-007');
        $code = 'OPS-' . $bill->getId();

        $created = $this->updateTool()->__invoke(bill_id: $bill->getId(), subaccount: $code);
        $reused = $this->updateTool()->__invoke(bill_id: $bill->getId(), subaccount: $code);

        $this->assertTrue($created['subaccount_created'], json_encode($created));
        $this->assertFalse($reused['subaccount_created']);
        $subaccountCodes = Bill::getById($bill->getId())->lines
            ->map(fn (BillLine $line) => $line->subaccount?->sub_code)
            ->unique()
            ->all();
        $this->assertSame([$code], $subaccountCodes);
    }

    public function test_update_refuses_a_bill_that_is_no_longer_a_draft(): void
    {
        $bill = $this->draftBill('Pending Vendor LLC', 'EDT-002');
        $this->submitTool()->__invoke($bill->getId());

        $result = $this->updateTool()->__invoke(bill_id: $bill->getId(), invoice_number: 'EDT-002-CHANGED');

        $this->assertFalse($result['updated']);
        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
        $this->assertSame('not_a_draft', $result['reason']);
        $this->assertSame('EDT-002', Bill::getById($bill->getId())->bill_number);
    }

    public function test_update_reports_an_unknown_account_a_bad_date_and_an_empty_request(): void
    {
        $bill = $this->draftBill('Checks Vendor LLC', 'EDT-003');
        $update = fn (mixed ...$changes): array => $this->updateTool()->__invoke($bill->getId(), ...$changes);

        $this->assertSame('account_not_found', $update(gl_account_number: '999999')['reason']);
        $this->assertSame('invalid_date', $update(due_date: '31/10/2026')['reason']);
        $this->assertSame('invalid_date', $update(due_date: '2026-02-31')['reason']);
        $this->assertSame('nothing_to_change', $update()['reason']);
        $this->assertSame('nothing_to_change', $update(notes: '   ')['reason']);
        $missing = $this->updateTool()->__invoke(bill_id: 987654321, notes: 'x');
        $this->assertSame('bill_not_found', $missing['reason']);
    }

    public function test_update_reports_an_invoice_number_the_vendor_already_has(): void
    {
        $vendor = $this->seedTestOrganization('Twin Numbers LLC');
        $this->draftBillFor($vendor, 'EDT-004');
        $second = $this->draftBillFor($vendor, 'EDT-005');

        $result = $this->updateTool()->__invoke(bill_id: $second->getId(), invoice_number: 'EDT-004');

        $this->assertFalse($result['updated']);
        $this->assertSame('duplicate_invoice', $result['reason']);
        $this->assertSame('EDT-005', Bill::getById($second->getId())->bill_number);
    }

    public function test_submit_sends_a_draft_to_approval_and_never_approves_it(): void
    {
        $bill = $this->draftBill('Submit Vendor LLC', 'SUB-001');

        $result = $this->submitTool()->__invoke($bill->getId());

        $this->assertTrue($result['submitted'], json_encode($result));
        $this->assertSame('pending_approval', $result['document_status']);
        $this->assertSame(
            BillDocumentStatusEnum::PENDING_APPROVAL,
            Bill::getById($bill->getId())->document_status,
        );
    }

    public function test_submit_refuses_a_bill_without_a_vendor_and_one_already_pending(): void
    {
        $noVendor = $this->draftBill('No Vendor LLC', 'SUB-002');
        Bill::query()->whereKey($noVendor->getId())->update(['vendor_organization_id' => null]);

        $this->assertSame('vendor_required', $this->submitTool()->__invoke($noVendor->getId())['reason']);

        $pending = $this->draftBill('Already Pending LLC', 'SUB-003');
        $this->submitTool()->__invoke($pending->getId());

        $again = $this->submitTool()->__invoke($pending->getId());
        $this->assertSame('already_pending_approval', $again['reason']);
        $this->assertSame('bill_not_found', $this->submitTool()->__invoke(987654321)['reason']);
    }

    public function test_a_draft_can_be_corrected_and_then_sent_for_approval(): void
    {
        $bill = $this->draftBill('Flow Vendor LLC', 'FLW-001');

        $update = $this->updateTool()->__invoke(
            bill_id: $bill->getId(),
            gl_account_number: $this->accountCode(AccountSubTypeEnum::PROFESSIONAL_SERVICES),
        );
        $submit = $this->submitTool()->__invoke($bill->getId());

        $this->assertTrue($update['updated']);
        $this->assertTrue($submit['submitted']);
        $this->assertSame('pending_approval', Bill::getById($bill->getId())->document_status->value);
    }

    private function updateTool(): UpdateApBillTool
    {
        return new UpdateApBillTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function submitTool(): SubmitApBillTool
    {
        return new SubmitApBillTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function accountCode(AccountSubTypeEnum $subType): string
    {
        return (string) Account::query()
            ->whereKey($this->accountIdBySubType($subType))
            ->value('account_number');
    }

    private function draftBill(string $vendorName, string $number): Bill
    {
        return $this->draftBillFor($this->seedTestOrganization($vendorName), $number);
    }

    private function draftBillFor(Organization $vendor, string $number): Bill
    {
        $account = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);

        return new CreateBillAction(
            new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Chairs',
                        quantity: 1,
                        unit_price_native: 100.0,
                        expense_account_id: $account,
                    ),
                    new BillLineData(
                        description: 'Lamps',
                        quantity: 1,
                        unit_price_native: 50.0,
                        expense_account_id: $account,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_number: $number,
                taxLines: new DataCollection(BillTaxLineData::class, [
                    new BillTaxLineData(name: 'Sales tax', tax_amount_native: 0.0),
                ]),
            ),
            static::$cachedUser,
        )->execute();
    }
}
