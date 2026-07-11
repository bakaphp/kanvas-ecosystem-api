<?php

declare(strict_types=1);

namespace Tests\Scribe\Bills;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Bills\Actions\ApproveBillAction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\RejectBillAction;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\SyncWorkflowStub;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class BillApprovalActionTest extends ScribeTestCase
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

    private function postedBillJeCount(int $billId): int
    {
        return JournalEntry::query()
            ->where('source_type', 'bill')
            ->where('source_id', $billId)
            ->count();
    }

    public function test_submit_parks_bill_pending_with_a_queue_item_and_no_je(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $bill = $this->draftBill($vendor);

        $submitted = new SubmitBillForApprovalAction($bill, static::$cachedUser)->execute();

        $this->assertSame(BillDocumentStatusEnum::PENDING_APPROVAL, $submitted->document_status);
        $this->assertSame(0, $this->postedBillJeCount($bill->id), 'No JE until approved.');

        $item = ApprovalQueueItem::query()
            ->where('action_type', 'approve_bill')
            ->where('target_type', 'bill')
            ->where('target_id', $bill->id)
            ->first();
        $this->assertNotNull($item);
        $this->assertSame(ApprovalQueueStatusEnum::PENDING, $item->status);
    }

    public function test_approve_receives_bill_posts_je_and_closes_queue_item(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $bill = $this->draftBill($vendor);
        new SubmitBillForApprovalAction($bill, static::$cachedUser)->execute();

        $approved = new ApproveBillAction($bill->refresh(), $vendor, static::$cachedUser)->execute();

        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $approved->document_status);
        $this->assertNotNull($approved->bill_number, 'Bill number allocated on receive.');
        $this->assertSame(1, $this->postedBillJeCount($bill->id), 'AP JE posted on approval.');

        $item = ApprovalQueueItem::query()
            ->where('action_type', 'approve_bill')->where('target_id', $bill->id)->first();
        $this->assertSame(ApprovalQueueStatusEnum::APPROVED, $item->status);
        $this->assertSame((int) static::$cachedUser->getId(), (int) $item->approved_by_users_id);
    }

    /**
     * A persisted-bill stand-in that records fireWorkflow calls instead of dispatching them — avoids
     * a real workflow rule while proving the action fires the right event/params.
     */
    private function spyBill(Bill $real): Bill
    {
        $spy = new class () extends Bill {
            /** @var array<int, array{event: string, params: array<string, mixed>}> */
            public array $firedWorkflows = [];

            public function fireWorkflow(string $event, bool $async = true, array $params = []): ?SyncWorkflowStub
            {
                $this->firedWorkflows[] = ['event' => $event, 'params' => $params];

                return null;
            }
        };

        $spy->setRawAttributes($real->getAttributes(), true);
        $spy->exists = true;

        return $spy;
    }

    public function test_approve_fires_status_transition_event_to_received(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $real = $this->draftBill($vendor);
        new SubmitBillForApprovalAction($real, static::$cachedUser)->execute();

        $spy = $this->spyBill($real->refresh());
        new ApproveBillAction($spy, $vendor, static::$cachedUser)->execute();

        $this->assertCount(1, $spy->firedWorkflows);
        $this->assertSame(WorkflowEnum::STATUS_TRANSITION->value, $spy->firedWorkflows[0]['event']);
        $this->assertSame(BillDocumentStatusEnum::RECEIVED->value, $spy->firedWorkflows[0]['params']['to']);
        $this->assertSame('bill', $spy->firedWorkflows[0]['params']['entity']);
    }

    public function test_reject_fires_status_transition_event_to_draft(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $real = $this->draftBill($vendor);
        new SubmitBillForApprovalAction($real, static::$cachedUser)->execute();

        $spy = $this->spyBill($real->refresh());
        new RejectBillAction($spy, static::$cachedUser, reason: 'Wrong PO')->execute();

        $this->assertCount(1, $spy->firedWorkflows);
        $this->assertSame(WorkflowEnum::STATUS_TRANSITION->value, $spy->firedWorkflows[0]['event']);
        $this->assertSame(BillDocumentStatusEnum::DRAFT->value, $spy->firedWorkflows[0]['params']['to']);
        $this->assertSame('bill', $spy->firedWorkflows[0]['params']['entity']);
    }

    public function test_reject_returns_bill_to_draft_and_marks_queue_item_rejected(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $bill = $this->draftBill($vendor);
        new SubmitBillForApprovalAction($bill, static::$cachedUser)->execute();

        $rejected = new RejectBillAction($bill->refresh(), static::$cachedUser, reason: 'Wrong PO')->execute();

        $this->assertSame(BillDocumentStatusEnum::DRAFT, $rejected->document_status);
        $this->assertSame(0, $this->postedBillJeCount($bill->id), 'No JE on rejection.');

        $item = ApprovalQueueItem::query()
            ->where('action_type', 'approve_bill')->where('target_id', $bill->id)->first();
        $this->assertSame(ApprovalQueueStatusEnum::REJECTED, $item->status);
        $this->assertSame('Wrong PO', $item->reason);
    }
}
