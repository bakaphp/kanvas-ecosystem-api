<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Kanvas\Guild\Organizations\Actions\AddApproverToOrganizationAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Purchasing\Approvals\ApprovePurchaseOrderHandler;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderPdfService;
use Tests\Scribe\Approvals\Concerns\SeedsBillApprovals;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestBill;
use Tests\Traits\CreatesTestPurchaseOrder;

class PurchaseOrderGraphQLTest extends ScribeTestCase
{
    use CreatesTestBill;
    use CreatesTestPurchaseOrder;
    use SeedsBillApprovals;

    private const string CREATE = '
        mutation ($input: ScribePurchaseOrderInput!) {
            createScribePurchaseOrder(input: $input) {
                id order_number document_status currency order_total source
                vendor { id }
                lines { line_number description order_qty open_qty unit_cost }
            }
        }';

    public function test_an_order_is_created_edited_and_cancelled_through_the_api(): void
    {
        $vendor = $this->seedTestOrganization('Graph Vendor');

        $created = $this->graphQL(self::CREATE, ['input' => [
            'vendor_id' => $vendor->getId(),
            'currency' => 'usd',
            'lines' => [['description' => 'Pallet of paper', 'quantity' => 3, 'unit_cost' => 20]],
        ]])->assertSuccessful()->json('data.createScribePurchaseOrder');

        $this->assertSame('DRAFT', $created['document_status']);
        $this->assertSame('USD', $created['currency']);
        $this->assertSame('kanvas', $created['source']);
        $this->assertSame((string) $vendor->getId(), $created['vendor']['id']);
        $this->assertStringStartsWith('PO-', $created['order_number']);

        $this->graphQL('
            mutation ($id: ID!, $input: UpdateScribePurchaseOrderInput!) {
                updateScribePurchaseOrder(id: $id, input: $input) { order_total notes lines { description } }
            }', [
            'id' => $created['id'],
            'input' => [
                'notes' => 'Deliver to the back dock',
                'lines' => [['description' => 'Chairs', 'quantity' => 2, 'unit_cost' => 50]],
            ],
        ])
            ->assertJsonPath('data.updateScribePurchaseOrder.notes', 'Deliver to the back dock')
            ->assertJsonPath('data.updateScribePurchaseOrder.lines.0.description', 'Chairs');

        $this->graphQL('
            mutation ($id: ID!, $reason: String) {
                cancelScribePurchaseOrder(id: $id, reason: $reason) { document_status cancel_reason cancelled_at }
            }', ['id' => $created['id'], 'reason' => 'Vendor changed terms'])
            ->assertJsonPath('data.cancelScribePurchaseOrder.document_status', 'CANCELLED')
            ->assertJsonPath('data.cancelScribePurchaseOrder.cancel_reason', 'Vendor changed terms');

        $this->graphQL('
            query {
                scribePurchaseOrders(where: { column: DOCUMENT_STATUS, value: "cancelled" }) { data { id } }
            }')
            ->assertSuccessful()
            ->assertJsonFragment(['id' => $created['id']]);
    }

    public function test_a_cancelled_order_cannot_be_edited(): void
    {
        $vendor = $this->seedTestOrganization('Locked Vendor');
        $id = $this->graphQL(self::CREATE, ['input' => [
            'vendor_id' => $vendor->getId(),
            'currency' => 'USD',
            'lines' => [['description' => 'Item', 'quantity' => 1, 'unit_cost' => 5]],
        ]])->json('data.createScribePurchaseOrder.id');

        $this->graphQL('mutation ($id: ID!) { cancelScribePurchaseOrder(id: $id) { id } }', ['id' => $id])
            ->assertSuccessful();

        $this->graphQL('
            mutation ($id: ID!) {
                updateScribePurchaseOrder(id: $id, input: { notes: "Late change" }) { id }
            }', ['id' => $id])
            ->assertGraphQLErrorMessage('Purchase order ' . $this->orderNumber($id) . ' is cancelled: only a draft can be edited.');
    }

    public function test_an_order_is_submitted_approved_or_rejected_by_the_vendors_approver_through_the_api(): void
    {
        $vendor = $this->seedTestOrganization('Approval Graph Vendor');
        new AddApproverToOrganizationAction($vendor, static::$cachedUser)->execute();
        $this->seedPolicy(PurchaseOrder::class, 'approve_purchase_order', 'vendor', ApprovePurchaseOrderHandler::class);

        $approvedId = $this->submittedOrderId($vendor);
        $this->graphQL('
            mutation ($id: ID!) {
                approveScribePurchaseOrder(id: $id) { document_status approved_at }
            }', ['id' => $approvedId])
            ->assertJsonPath('data.approveScribePurchaseOrder.document_status', 'APPROVED')
            ->assertJsonPath('data.approveScribePurchaseOrder.approved_at', fn (?string $at): bool => $at !== null);

        $rejectedId = $this->submittedOrderId($vendor);
        $this->graphQL('
            mutation ($id: ID!) {
                rejectScribePurchaseOrder(id: $id, reason: "Wrong vendor") { document_status notes }
            }', ['id' => $rejectedId])
            ->assertJsonPath('data.rejectScribePurchaseOrder.document_status', 'DRAFT')
            ->assertJsonPath('data.rejectScribePurchaseOrder.notes', 'Rejected: Wrong vendor');
    }

    public function test_an_order_without_an_approval_policy_cannot_be_submitted_through_the_api(): void
    {
        $vendor = $this->seedTestOrganization('No Policy Graph Vendor');
        $order = $this->createPurchaseOrderFor($vendor);

        $this->graphQL('
            mutation ($id: ID!) {
                submitScribePurchaseOrderForApproval(id: $id) { id }
            }', ['id' => (string) $order->getId()])
            ->assertGraphQLErrorMessage('This company has no approval policy for purchase orders, so there is nobody to approve it. Seed the approval policies first.');
    }

    public function test_the_order_renders_as_a_pdf_attached_to_it(): void
    {
        $this->skipWithoutPdfBinary();

        $order = $this->createPurchaseOrderFor($this->seedTestOrganization('Pdf Graph Vendor'));

        $this->graphQL('
            mutation ($id: ID!) {
                generateScribePurchaseOrderPdf(id: $id) { name }
            }', ['id' => (string) $order->getId()])
            ->assertJsonPath('data.generateScribePurchaseOrderPdf.name', 'purchase-order-' . $order->order_number . '.pdf');

        $this->assertNotNull($order->fresh()->getFileByName(PurchaseOrderPdfService::FIELD_NAME));
    }

    public function test_goods_are_received_and_a_bill_is_matched_and_linked_through_the_api(): void
    {
        $vendor = $this->seedTestOrganization('Receiving Graph Vendor');
        $order = $this->createPurchaseOrderFor($vendor, [
            ['description' => 'Pallet of paper', 'quantity' => 10, 'unit_cost' => 12.5, 'sku' => 'PAP-1'],
        ]);
        $order->document_status = PurchaseOrderStatusEnum::SENT;
        $order->save();
        $id = (string) $order->getId();

        $this->graphQL('
            mutation ($id: ID!) {
                receiveScribePurchaseOrder(
                    id: $id
                    received_on: "2026-12-02"
                    reference: "Delivery note 9"
                    lines: [{ line_number: 1, quantity: 6 }]
                ) {
                    receipt_number
                    reference
                    lines { quantity line { line_number received_qty open_qty } }
                }
            }', ['id' => $id])
            ->assertJsonPath('data.receiveScribePurchaseOrder.reference', 'Delivery note 9')
            ->assertJsonPath('data.receiveScribePurchaseOrder.lines.0.quantity', 6)
            ->assertJsonPath('data.receiveScribePurchaseOrder.lines.0.line.open_qty', 4);

        $this->graphQL('
            mutation ($id: ID!) {
                receiveScribePurchaseOrder(id: $id, received_on: "2026-12-03", lines: [{ line_number: 1, quantity: 9 }]) { id }
            }', ['id' => $id])
            ->assertGraphQLErrorMessage('Line 1: 9 received but only 4 is open on the order.');

        $bill = $this->createBillFor($vendor, [
            ['description' => 'Pallet of paper', 'quantity' => 8, 'unit_price' => 12.5, 'sku' => 'PAP-1'],
        ]);
        $variables = ['bill' => (string) $bill->getId(), 'order' => $id];

        $this->graphQL('
            query ($bill: ID!, $order: ID!) {
                matchScribeBillToPurchaseOrder(bill_id: $bill, purchase_order_id: $order) {
                    matched
                    discrepancies { key order_value received_value billed_value }
                }
            }', $variables)
            ->assertJsonPath('data.matchScribeBillToPurchaseOrder.matched', false)
            ->assertJsonPath('data.matchScribeBillToPurchaseOrder.discrepancies.0.key', 'line_1_billed_more_than_received')
            ->assertJsonPath('data.matchScribeBillToPurchaseOrder.discrepancies.0.received_value', '6');

        $this->graphQL('
            mutation ($bill: ID!, $order: ID!) {
                linkScribeBillToPurchaseOrder(bill_id: $bill, purchase_order_id: $order) { id }
            }', $variables)
            ->assertGraphQLErrorMessage("Bill {$bill->bill_number} does not match purchase order {$order->order_number}: line_1_billed_more_than_received.");

        $this->graphQL('
            query {
                scribePurchaseOrders(where: { column: ORDER_NUMBER, value: "' . $order->order_number . '" }) {
                    data { receipts { receipt_number } }
                }
            }')
            ->assertJsonCount(1, 'data.scribePurchaseOrders.data.0.receipts');
    }

    private function submittedOrderId(Organization $vendor): string
    {
        $order = $this->createPurchaseOrderFor($vendor);

        $this->graphQL('
            mutation ($id: ID!) {
                submitScribePurchaseOrderForApproval(id: $id) { document_status }
            }', ['id' => (string) $order->getId()])
            ->assertJsonPath('data.submitScribePurchaseOrderForApproval.document_status', 'PENDING_APPROVAL');

        return (string) $order->getId();
    }

    private function orderNumber(string $id): string
    {
        return PurchaseOrder::query()->findOrFail($id)->order_number;
    }
}
