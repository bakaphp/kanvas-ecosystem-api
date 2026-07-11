<?php

declare(strict_types=1);

namespace Tests\Scribe\Bills;

use App\GraphQL\Scribe\Mutations\Bills\BillMutation;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class BillApprovalMutationTest extends ScribeTestCase
{
    private function draftBill(Organization $vendor): Bill
    {
        return new CreateBillAction(
            new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Raw materials',
                        quantity: 1,
                        unit_price_native: 500.0,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-15'),
            ),
            static::$cachedUser,
        )->execute();
    }

    public function test_submit_then_approve_mutations_drive_the_bill_to_received(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $bill = $this->draftBill($vendor);
        $mutation = new BillMutation();

        $submitted = $mutation->submitForApproval(null, ['id' => (string) $bill->id]);
        $this->assertSame(BillDocumentStatusEnum::PENDING_APPROVAL, $submitted->document_status);

        $approved = $mutation->approve(null, ['id' => (string) $bill->id]);
        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $approved->document_status);
    }

    public function test_reject_mutation_returns_the_bill_to_draft(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $bill = $this->draftBill($vendor);
        $mutation = new BillMutation();

        $mutation->submitForApproval(null, ['id' => (string) $bill->id]);
        $rejected = $mutation->reject(null, ['id' => (string) $bill->id, 'reason' => 'Wrong PO']);

        $this->assertSame(BillDocumentStatusEnum::DRAFT, $rejected->document_status);
    }
}
