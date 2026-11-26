<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CancelPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreatePurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GeneratePurchaseOrderPdfTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenPurchaseOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SendPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SubmitPurchaseOrderForApprovalTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\UpdatePurchaseOrderTool;
use Kanvas\Scribe\Purchasing\Approvals\ApprovePurchaseOrderHandler;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderPdfService;
use Tests\Scribe\Approvals\Concerns\SeedsBillApprovals;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\AssertsToolRunKeys;
use Tests\Traits\CreatesTestPurchaseOrder;

class PurchaseOrderToolsTest extends ScribeTestCase
{
    use AssertsToolRunKeys;
    use CreatesTestPurchaseOrder;
    use SeedsBillApprovals;

    public function test_the_agent_drafts_edits_and_cancels_an_order(): void
    {
        $vendor = $this->seedTestOrganization('Tool Vendor');

        $created = $this->createTool()->__invoke(
            vendor_organization_id: $vendor->getId(),
            currency: 'USD',
            lines: [['description' => 'Pallet of paper', 'quantity' => 4, 'unit_cost' => 25]],
            notes: 'Deliver on Monday',
        );

        $this->assertTrue($created['created']);
        $this->assertSame('draft', $created['document_status']);
        $this->assertSame(100.0, $created['order_total']);

        $updated = $this->updateTool()->__invoke(
            order_number: $created['order_number'],
            lines: [['description' => 'Pallet of paper', 'quantity' => 6, 'unit_cost' => 25]],
        );

        $this->assertTrue($updated['updated']);
        $this->assertSame(150.0, $updated['order_total']);

        $found = $this->findTool()->__invoke(order_number: $created['order_number']);
        $this->assertSame('draft', $found['status']);

        $cancelled = $this->cancelTool()->__invoke(order_number: $created['order_number'], reason: 'Not needed');

        $this->assertTrue($cancelled['cancelled']);
        $this->assertSame('cancelled', $cancelled['document_status']);
    }

    public function test_a_cancelled_order_is_no_longer_listed_as_open(): void
    {
        $vendor = $this->seedTestOrganization('Listed Vendor');
        $open = $this->createTool()->__invoke($vendor->getId(), 'USD', [['description' => 'Kept', 'quantity' => 1, 'unit_cost' => 5]]);
        $gone = $this->createTool()->__invoke($vendor->getId(), 'USD', [['description' => 'Dropped', 'quantity' => 1, 'unit_cost' => 5]]);
        $this->cancelTool()->__invoke(order_number: $gone['order_number']);

        $listed = collect($this->listTool()->__invoke(vendor_organization_id: $vendor->getId())['purchase_orders'])
            ->pluck('order_number')
            ->all();

        $this->assertContains($open['order_number'], $listed);
        $this->assertNotContains($gone['order_number'], $listed);
    }

    public function test_an_unknown_vendor_is_reported_not_guessed(): void
    {
        $result = $this->createTool()->__invoke(
            vendor_organization_id: 999999999,
            currency: 'USD',
            lines: [['description' => 'Item', 'quantity' => 1, 'unit_cost' => 5]],
        );

        $this->assertFalse($result['created']);
        $this->assertSame('vendor_not_found', $result['reason']);
    }

    public function test_an_order_with_a_bad_line_is_refused_with_the_reason(): void
    {
        $vendor = $this->seedTestOrganization('Bad Line Vendor');

        $result = $this->createTool()->__invoke(
            vendor_organization_id: $vendor->getId(),
            currency: 'USD',
            lines: [['description' => 'Nothing', 'quantity' => 0, 'unit_cost' => 5]],
        );

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('quantity above zero', $result['message']);
    }

    public function test_an_unknown_order_number_is_reported(): void
    {
        $this->assertSame('purchase_order_not_found', $this->updateTool()->__invoke(order_number: 'PO-999999999')['reason']);
        $this->assertSame('purchase_order_not_found', $this->cancelTool()->__invoke(order_number: 'PO-999999999')['reason']);
    }

    public function test_an_order_that_cannot_be_edited_says_why(): void
    {
        $vendor = $this->seedTestOrganization('Locked Tool Vendor');
        $order = $this->createTool()->__invoke($vendor->getId(), 'USD', [['description' => 'Item', 'quantity' => 1, 'unit_cost' => 5]]);
        $this->cancelTool()->__invoke(order_number: $order['order_number']);

        $result = $this->updateTool()->__invoke(order_number: $order['order_number'], notes: 'Too late');

        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('only a draft can be edited', $result['message']);
    }

    public function test_the_order_tools_key_their_run_budget_by_inputs(): void
    {
        $this->assertRunKeyFollowsInputs(new CreatePurchaseOrderTool());
        $this->assertRunKeyFollowsInputs(new UpdatePurchaseOrderTool());
        $this->assertRunKeyFollowsInputs(new CancelPurchaseOrderTool());
        $this->assertRunKeyFollowsInputs(new SubmitPurchaseOrderForApprovalTool());
        $this->assertRunKeyFollowsInputs(new SendPurchaseOrderTool());
        $this->assertRunKeyFollowsInputs(new GeneratePurchaseOrderPdfTool());
    }

    public function test_the_agent_submits_an_order_to_the_vendors_approver_only_when_a_policy_exists(): void
    {
        $vendor = $this->seedTestOrganization('Submit Tool Vendor');
        $order = $this->createPurchaseOrderFor($vendor);
        $tool = new SubmitPurchaseOrderForApprovalTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser);

        $refused = $tool->__invoke(order_number: $order->order_number);
        $this->assertFalse($refused['submitted']);
        $this->assertStringContainsString('no approval policy', $refused['message']);

        $approver = $this->linkApprover($vendor, 'po-tool-approver');
        $this->seedPolicy(PurchaseOrder::class, 'approve_purchase_order', 'vendor', ApprovePurchaseOrderHandler::class);

        $submitted = $tool->__invoke(order_number: $order->order_number);
        $this->assertTrue($submitted['submitted']);
        $this->assertSame('pending_approval', $submitted['document_status']);
        $this->assertSame([$approver->email], $submitted['approvers']);
    }

    public function test_an_order_that_is_not_approved_is_not_sent(): void
    {
        $order = $this->createPurchaseOrderFor($this->seedTestOrganization('Send Tool Vendor'));

        $result = new SendPurchaseOrderTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(order_number: $order->order_number, to: ['purchasing@vendor.example.test']);

        $this->assertFalse($result['sent']);
        $this->assertSame('send_failed', $result['reason']);
        $this->assertSame('purchase_order_not_found', $this->sendTool()->__invoke(order_number: 'PO-999999999', to: [])['reason']);
    }

    public function test_the_agent_renders_the_order_as_a_pdf(): void
    {
        $this->skipWithoutPdfBinary();

        $order = $this->createPurchaseOrderFor($this->seedTestOrganization('Pdf Tool Vendor'));

        $result = new GeneratePurchaseOrderPdfTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(order_number: $order->order_number);

        $this->assertTrue($result['generated']);
        $this->assertSame('purchase-order-' . $order->order_number . '.pdf', $result['file_name']);
        $this->assertNotNull($order->fresh()->getFileByName(PurchaseOrderPdfService::FIELD_NAME));
    }

    private function sendTool(): SendPurchaseOrderTool
    {
        return new SendPurchaseOrderTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function createTool(): CreatePurchaseOrderTool
    {
        return new CreatePurchaseOrderTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function updateTool(): UpdatePurchaseOrderTool
    {
        return new UpdatePurchaseOrderTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function cancelTool(): CancelPurchaseOrderTool
    {
        return new CancelPurchaseOrderTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function findTool(): FindPurchaseOrderTool
    {
        return new FindPurchaseOrderTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function listTool(): ListOpenPurchaseOrdersTool
    {
        return new ListOpenPurchaseOrdersTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }
}
