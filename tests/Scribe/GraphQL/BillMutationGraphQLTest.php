<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Tests\Scribe\ScribeTestCase;

/**
 * Smoke coverage for the PR 10 Bill GraphQL surface:
 *   - createScribeBill (mutation → CreateBillAction)
 *   - receiveScribeBill (mutation → ReceiveBillAction; requires vendor)
 *   - voidScribeBill
 *   - markScribeBillPaid
 *   - scribeBills list query with where filter
 *
 * Receive + Void require a real PayeeInterface from Guild. For this smoke test we skip the full
 * receive/void flow (no Guild factory wired here) — the Action-level Pr10BillsTest already proves
 * that path works end-to-end with the StubPayee. This test class verifies the GraphQL wiring:
 * resolvers reachable, errors propagate, queries paginate.
 */
class BillMutationGraphQLTest extends ScribeTestCase
{
    public function test_create_bill_mutation_writes_draft(): void
    {
        $expenseId = $this->accountIdBySubType(AccountSubTypeEnum::CLOUD_HOSTING);

        $response = $this->graphQL('
            mutation($input: ScribeBillInput!) {
                createScribeBill(input: $input) {
                    id
                    document_status
                    total_native
                    currency
                    lines {
                        id
                        description
                        expense_account { id }
                    }
                }
            }
        ', [
            'input' => [
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'bill_number' => 'VENDOR-001',
                'bill_date' => '2026-06-15',
                'net_terms_days' => 30,
                'lines' => [[
                    'description' => 'Datadog Pro subscription',
                    'quantity' => 1.0,
                    'unit_price_native' => 800.0,
                    'expense_account_id' => $expenseId,
                ]],
            ],
        ])->assertSuccessful();

        $response->assertJsonPath('data.createScribeBill.document_status', 'DRAFT');
        $this->assertEquals(800.0, (float) $response->json('data.createScribeBill.total_native'));
        $this->assertSame('USD', $response->json('data.createScribeBill.currency'));
        $this->assertSame((string) $expenseId, $response->json('data.createScribeBill.lines.0.expense_account.id'));
    }

    public function test_void_draft_bill_returns_graphql_error(): void
    {
        $expenseId = $this->accountIdBySubType(AccountSubTypeEnum::CLOUD_HOSTING);

        // Create a draft, then try to void it (should fail per state machine)
        $create = $this->graphQL('
            mutation($input: ScribeBillInput!) {
                createScribeBill(input: $input) { id }
            }
        ', [
            'input' => [
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'bill_number' => 'VENDOR-002',
                'lines' => [[
                    'description' => 'Line',
                    'unit_price_native' => 100.0,
                    'expense_account_id' => $expenseId,
                ]],
            ],
        ])->assertSuccessful();

        $billId = $create->json('data.createScribeBill.id');

        $voidResponse = $this->graphQL('
            mutation($id: ID!) { voidScribeBill(id: $id, void_reason_code: "mistake") { id } }
        ', ['id' => $billId]);

        $payload = $voidResponse->json();
        $this->assertArrayHasKey(
            'errors',
            $payload,
            'Voiding a draft Bill should produce a GraphQL error (no Receive JE to reverse).',
        );
    }

    public function test_mark_paid_on_zero_balance_returns_bill(): void
    {
        $expenseId = $this->accountIdBySubType(AccountSubTypeEnum::CLOUD_HOSTING);

        // Create a draft bill with $0 total — markBillPaid on zero balance is a degenerate but legal call
        $create = $this->graphQL('
            mutation($input: ScribeBillInput!) {
                createScribeBill(input: $input) { id }
            }
        ', [
            'input' => [
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'lines' => [[
                    'description' => 'Free trial',
                    'unit_price_native' => 0.0,
                    'expense_account_id' => $expenseId,
                ]],
            ],
        ])->assertSuccessful();

        $billId = $create->json('data.createScribeBill.id');

        // Mark paid on a draft with $0 balance — should succeed (no allocations needed; the recompute lands
        // on balance_due=0 and the state-machine flip is gated by canTransition)
        $response = $this->graphQL('
            mutation($id: ID!) { markScribeBillPaid(id: $id) { id document_status } }
        ', ['id' => $billId])->assertSuccessful();

        // Draft → Paid isn't allowed by the state machine; markBillPaid stays a no-op on drafts.
        $response->assertJsonPath('data.markScribeBillPaid.document_status', 'DRAFT');
    }

    public function test_scribe_bills_list_query_filters_by_status(): void
    {
        $expenseId = $this->accountIdBySubType(AccountSubTypeEnum::CLOUD_HOSTING);

        // Seed one draft
        $this->graphQL('
            mutation($input: ScribeBillInput!) {
                createScribeBill(input: $input) { id }
            }
        ', [
            'input' => [
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'lines' => [[
                    'description' => 'Line',
                    'unit_price_native' => 50.0,
                    'expense_account_id' => $expenseId,
                ]],
            ],
        ])->assertSuccessful();

        $response = $this->graphQL('
            query {
                scribeBills(
                    first: 25
                    where: { column: DOCUMENT_STATUS, operator: EQ, value: "draft" }
                ) {
                    data { id document_status }
                    paginatorInfo { total }
                }
            }
        ')->assertSuccessful();

        $this->assertGreaterThanOrEqual(
            1,
            (int) $response->json('data.scribeBills.paginatorInfo.total'),
            'At least the bill we just created should appear in the draft filter.',
        );

        foreach ($response->json('data.scribeBills.data') as $row) {
            $this->assertSame('DRAFT', $row['document_status']);
        }
    }

    public function test_receive_without_vendor_returns_graphql_error(): void
    {
        $expenseId = $this->accountIdBySubType(AccountSubTypeEnum::CLOUD_HOSTING);

        $create = $this->graphQL('
            mutation($input: ScribeBillInput!) {
                createScribeBill(input: $input) { id }
            }
        ', [
            'input' => [
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'lines' => [[
                    'description' => 'Line',
                    'unit_price_native' => 100.0,
                    'expense_account_id' => $expenseId,
                ]],
            ],
        ])->assertSuccessful();

        $billId = $create->json('data.createScribeBill.id');
        $this->assertSame(
            0,
            (int) (Bill::query()->where('id', $billId)->value('vendor_billable_id') ?? 0),
            'Smoke: bill has no vendor pre-receive.',
        );

        $response = $this->graphQL('
            mutation($id: ID!) { receiveScribeBill(id: $id) { id } }
        ', ['id' => $billId]);

        $payload = $response->json();
        $this->assertArrayHasKey(
            'errors',
            $payload,
            'Receiving a bill with no vendor reference should error.',
        );
    }
}
