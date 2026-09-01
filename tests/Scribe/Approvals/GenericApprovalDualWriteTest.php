<?php

declare(strict_types=1);

namespace Tests\Scribe\Approvals;

use Illuminate\Support\Carbon;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Exceptions\ApprovalRequiredException;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Connectors\Acumatica\Actions\PushBillToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Approvals\ApproveAndPushBillHandler;
use Kanvas\Guild\Organizations\Actions\AddApproverToOrganizationAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApprovePendingItemTool;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\SubmitExpenseForApprovalAction;
use Kanvas\Scribe\Expenses\Approvals\ApproveExpenseHandler;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;
use Throwable;

/**
 * The adoption contract for the generic approvals domain: submitting a bill keeps writing the legacy
 * accounting.approval_queue row Apex depends on, and additionally opens an approval_requests row —
 * but only where the tenant has actually configured a policy.
 */
class GenericApprovalDualWriteTest extends ScribeTestCase
{
    public function test_a_tenant_with_no_policy_is_completely_unaffected(): void
    {
        $bill = $this->submitBill($this->seedTestOrganization('No Policy Vendor'));

        $this->assertSame(BillDocumentStatusEnum::PENDING_APPROVAL, $bill->document_status);
        $this->assertNotNull($this->legacyQueueItem($bill), 'The legacy queue row must still be written.');
        $this->assertNull($bill->pendingApproval(), 'No policy means no generic request.');
    }

    public function test_a_tenant_with_a_policy_gets_both_rows(): void
    {
        $vendor = $this->seedTestOrganization('Dual Write Vendor');
        $approver = $this->linkApprover($vendor);
        $this->seedBillPolicy();

        $bill = $this->submitBill($vendor);

        $this->assertNotNull($this->legacyQueueItem($bill), 'The legacy queue row must still be written.');

        $request = $bill->pendingApproval();
        $this->assertNotNull($request, 'A configured policy must also open a generic request.');
        $this->assertSame('approve_bill', $request->approval_type);
        $this->assertSame([$approver->email], $request->pendingApproverEmails());
    }

    public function test_the_payload_snapshot_records_what_the_approver_is_being_shown(): void
    {
        $vendor = $this->seedTestOrganization('Payload Vendor');
        $this->linkApprover($vendor);
        $this->seedBillPolicy();

        $request = $this->submitBill($vendor)->pendingApproval();

        $this->assertSame('USD', $request->payload['currency']);
        $this->assertSame(500.0, (float) $request->payload['total_native']);
    }

    /**
     * The vendor's own approver resolves through organization_approvers — the AP behaviour that used
     * to be a hardcoded match arm, now reached purely as policy data.
     */
    public function test_the_organization_approver_resolver_reproduces_the_ap_behaviour(): void
    {
        $vendor = $this->seedTestOrganization('Resolver Vendor');
        $one = $this->linkApprover($vendor, 'ap-one');
        $two = $this->linkApprover($vendor, 'ap-two');
        $this->seedBillPolicy();

        $emails = $this->submitBill($vendor)->pendingApproval()->pendingApproverEmails();

        $this->assertCount(2, $emails);
        $this->assertContains($one->email, $emails);
        $this->assertContains($two->email, $emails);
    }

    public function test_a_vendor_with_no_approver_yields_an_unassigned_request_not_a_silent_gap(): void
    {
        $vendor = $this->seedTestOrganization('Approverless Vendor');
        $this->seedBillPolicy();

        $request = $this->submitBill($vendor)->pendingApproval();

        $this->assertNotNull($request);
        $this->assertTrue($request->isUnassigned());
        $this->assertSame([], $request->pendingApproverEmails());
    }

    public function test_approving_through_the_generic_action_approves_the_bill(): void
    {
        $vendor = $this->seedTestOrganization('Approved Vendor');
        $approver = $this->linkApprover($vendor);
        $this->seedBillPolicy();

        $bill = $this->submitBill($vendor);
        $request = $bill->pendingApproval();

        $result = new ApproveAction($request, $approver)->execute();

        $this->assertSame(ApprovalStatusEnum::APPROVED, $request->refresh()->status);
        $this->assertSame('bill', $result->handlerResult['target_type']);
        $this->assertSame($bill->getId(), $result->handlerResult['target_id']);
        $this->assertSame(
            BillDocumentStatusEnum::RECEIVED,
            $bill->refresh()->document_status,
            'The handler must carry out the domain approval, not just close the request.'
        );
    }

    /**
     * Acumatica is unreachable in tests, so this also pins the contract Apex depends on: a push
     * failure comes back as data on a recorded approval, never as an exception, so the agent reports
     * the failure instead of marking the sheet Approved.
     */
    public function test_a_failed_push_is_reported_without_undoing_the_approval(): void
    {
        $vendor = $this->seedTestOrganization('Push Failure Vendor');
        $approver = $this->linkApprover($vendor);
        $this->seedBillPolicy();

        $request = $this->submitBill($vendor)->pendingApproval();

        $result = new ApproveAction($request, $approver)->execute();

        $this->assertSame(ApprovalStatusEnum::APPROVED, $request->refresh()->status);
        $this->assertFalse($result->handlerResult['pushed']);
        $this->assertNotNull($result->handlerResult['push_error']);
    }

    public function test_an_expense_dual_writes_and_approves_through_the_generic_action(): void
    {
        $vendor = $this->seedTestOrganization('Expense Vendor');
        $approver = $this->linkApprover($vendor, 'expense-approver');
        $this->seedPolicy(Expense::class, 'approve_expense', 'vendor', ApproveExpenseHandler::class);

        $expense = $this->submitExpense($vendor);

        $request = $expense->pendingApproval();
        $this->assertNotNull($request, 'A configured policy must open a generic request for expenses too.');
        $this->assertSame([$approver->email], $request->pendingApproverEmails());

        $result = new ApproveAction($request, $approver)->execute();

        $this->assertSame('expense', $result->handlerResult['target_type']);
        $this->assertSame(
            ExpenseStatusEnum::APPROVED,
            $expense->refresh()->status,
            'The domain handler must carry out the approval, not just close the request.'
        );
    }

    /**
     * The expense handler pushes nowhere, so unlike bills and invoices it lives in the Scribe domain
     * rather than in the Acumatica connector — and it reports no push rather than a failed one.
     */
    public function test_a_handler_with_no_erp_push_reports_no_push_error(): void
    {
        $vendor = $this->seedTestOrganization('No Push Vendor');
        $approver = $this->linkApprover($vendor, 'no-push-approver');
        $this->seedPolicy(Expense::class, 'approve_expense', 'vendor', ApproveExpenseHandler::class);

        $request = $this->submitExpense($vendor)->pendingApproval();

        $result = new ApproveAction($request, $approver)->execute();

        $this->assertFalse($result->handlerResult['pushed']);
        $this->assertNull($result->handlerResult['push_error']);
    }

    private function submitExpense(Organization $vendor): Expense
    {
        $draft = new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Team lunch',
                        amount_native: 120.0,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::COMPANY_CARD,
                vendor: $vendor,
            ),
            user: static::$cachedUser,
        )->execute();

        return new SubmitExpenseForApprovalAction($draft, static::$cachedUser)->execute();
    }

    /**
     * The cutover: with a policy in place the tool must route through the generic engine and still
     * return the exact shape Apex's guidance reads.
     */
    public function test_the_agent_tool_routes_through_the_generic_engine_when_a_policy_exists(): void
    {
        $vendor = $this->seedTestOrganization('Tool Generic Vendor');
        $approver = $this->linkApprover($vendor, 'tool-approver');
        $this->seedBillPolicy();

        $bill = $this->submitBill($vendor);

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, $approver)
            ->__invoke(target_type: 'bill', target_id: $bill->getId());

        $this->assertTrue($result['approved']);
        $this->assertSame('bill', $result['target_type']);
        $this->assertArrayHasKey('pushed', $result);
        $this->assertArrayHasKey('next', $result);
        $this->assertNull($bill->pendingApproval(), 'The generic request must be closed, not left open.');
        $this->assertTrue($bill->isApproved());
    }

    public function test_the_agent_tool_refuses_someone_who_is_not_an_approver(): void
    {
        $vendor = $this->seedTestOrganization('Tool Refusal Vendor');
        $this->linkApprover($vendor, 'real-approver');
        $this->seedBillPolicy();

        $bill = $this->submitBill($vendor);
        $stranger = Users::factory()->create(['email' => 'stranger-' . uniqid() . '@example.test']);

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, $stranger)
            ->__invoke(target_type: 'bill', target_id: $bill->getId());

        $this->assertFalse($result['approved']);
        $this->assertSame('not_authorized', $result['reason']);
        $this->assertNotNull($bill->pendingApproval(), 'A refused call must leave the request open.');
    }

    /**
     * A tenant with no policy still gets the legacy engine, unchanged — that is what makes seeding a
     * policy the switch rather than deploying the code.
     */
    public function test_the_agent_tool_falls_back_to_the_legacy_engine_with_no_policy(): void
    {
        $vendor = $this->seedTestOrganization('Tool Legacy Vendor');
        $approver = Users::factory()->create(['email' => 'legacy-approver-' . uniqid() . '@example.test']);
        $vendor->set('ap_approver_email', $approver->email);

        $bill = $this->submitBill($vendor);
        $this->assertNull($bill->pendingApproval(), 'No policy means no generic request.');

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, $approver)
            ->__invoke(target_type: 'bill', target_id: $bill->getId());

        $this->assertTrue($result['approved']);
        $this->assertSame('bill', $result['target_type']);
    }

    public function test_the_agent_tool_tells_the_agent_not_to_update_the_sheet_before_quorum(): void
    {
        $vendor = $this->seedTestOrganization('Tool Quorum Vendor');
        $one = $this->linkApprover($vendor, 'quorum-one');
        $this->linkApprover($vendor, 'quorum-two');

        $policy = $this->seedBillPolicy();
        $steps = $policy->steps;
        $steps[0]['required_approvals'] = 2;
        $policy->steps = $steps;
        $policy->saveOrFail();

        $bill = $this->submitBill($vendor);

        $result = new ApprovePendingItemTool()
            ->withContext($this->kanvasApp, $this->company, $one)
            ->__invoke(target_type: 'bill', target_id: $bill->getId());

        $this->assertFalse($result['approved']);
        $this->assertTrue($result['recorded']);
        $this->assertSame('awaiting_quorum', $result['reason']);
        $this->assertStringContainsString('Do not update the tracking sheet', $result['message']);
    }

    /**
     * The seatbelt from the plan's enforcement section: the real guarantee is that the push only runs
     * from the approval handler, but a call site added later that skips it must fail loudly rather
     * than quietly send an unapproved bill to Acumatica.
     */
    public function test_pushing_a_bill_that_is_still_awaiting_approval_is_refused(): void
    {
        $vendor = $this->seedTestOrganization('Seatbelt Vendor');
        $this->linkApprover($vendor, 'seatbelt-approver');
        $this->seedBillPolicy();

        $bill = $this->submitBill($vendor);
        $this->assertNotNull($bill->pendingApproval());

        $this->expectException(ApprovalRequiredException::class);

        new PushBillToAcumaticaAction($bill)->execute();
    }

    public function test_pushing_an_ungated_bill_is_not_blocked_by_the_seatbelt(): void
    {
        $vendor = $this->seedTestOrganization('Ungated Push Vendor');
        $bill = $this->submitBill($vendor);

        $this->assertNull($bill->pendingApproval());

        // No policy, so no pending request — the guard must let it through to fail (or not) on its
        // own terms rather than refusing outright.
        try {
            new PushBillToAcumaticaAction($bill)->execute();
        } catch (ApprovalRequiredException $e) {
            $this->fail('The seatbelt must not block a bill that was never gated.');
        } catch (Throwable) {
            // Acumatica is unreachable in tests; reaching it at all is the point.
        }

        $this->assertTrue(true);
    }

    private function seedBillPolicy(): ApprovalPolicy
    {
        return $this->seedPolicy(Bill::class, 'approve_bill', 'vendor', ApproveAndPushBillHandler::class);
    }

    private function seedPolicy(
        string $model,
        string $approvalType,
        string $relation,
        string $handler
    ): ApprovalPolicy {
        return ApprovalPolicy::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'system_modules_id' => SystemModulesRepository::getByModelName($model, $this->kanvasApp)->getId(),
            'approval_type' => $approvalType,
            'steps' => [[
                'step' => 1,
                'resolver' => 'organization_approver',
                'config' => ['relation' => $relation],
                'required_approvals' => 1,
            ]],
            'handler' => $handler,
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);
    }

    private function linkApprover(Organization $vendor, string $prefix = 'ap-approver'): Users
    {
        $user = Users::factory()->create(['email' => $prefix . '-' . uniqid() . '@example.test']);
        new AddApproverToOrganizationAction($vendor, $user)->execute();

        return $user;
    }

    private function legacyQueueItem(Bill $bill): ?ApprovalQueueItem
    {
        return ApprovalQueueItem::query()
            ->where('action_type', 'approve_bill')
            ->where('target_type', 'bill')
            ->where('target_id', $bill->getId())
            ->first();
    }

    private function submitBill(Organization $vendor): Bill
    {
        $bill = new CreateBillAction(
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

        return new SubmitBillForApprovalAction($bill, static::$cachedUser)->execute();
    }
}
