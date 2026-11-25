<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CancelPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreatePurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenPurchaseOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\UpdatePurchaseOrderTool;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\AssertsToolRunKeys;

class PurchaseOrderToolsTest extends ScribeTestCase
{
    use AssertsToolRunKeys;

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
