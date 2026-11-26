<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CancelPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreatePurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenPurchaseOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\UpdatePurchaseOrderTool;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
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

        $cancelled = $this->cancelTool()
            ->__invoke(order_number: $created['order_number'], reason: 'Not needed');

        $this->assertTrue($cancelled['cancelled']);
        $this->assertSame('cancelled', $cancelled['document_status']);
    }

    public function test_a_cancelled_order_is_no_longer_listed_as_open(): void
    {
        $vendor = $this->seedTestOrganization('Listed Vendor');
        $open = $this->createTool()
            ->__invoke($vendor->getId(), 'USD', [['description' => 'Kept', 'quantity' => 1, 'unit_cost' => 5]]);
        $gone = $this->createTool()
            ->__invoke($vendor->getId(), 'USD', [['description' => 'Dropped', 'quantity' => 1, 'unit_cost' => 5]]);
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

    public function test_listing_orders_loads_open_lines_together_and_keeps_the_full_count(): void
    {
        $vendor = $this->seedTestOrganization('Batch Listed Vendor');
        $lines = array_map(
            static fn (int $number): array => ['description' => "Item {$number}", 'quantity' => 1, 'unit_cost' => 5],
            range(1, 12)
        );
        $first = $this->createTool()->__invoke($vendor->getId(), 'USD', $lines);
        $second = $this->createTool()->__invoke($vendor->getId(), 'USD', [$lines[0]]);
        $order = PurchaseOrder::query()->where('order_number', $first['order_number'])
            ->fromApp($this->kanvasApp)->fromCompany($this->company)->first();
        $this->assertNotNull($order);
        $order->lines()->where('line_number', 1)->update(['open_qty' => 0, 'received_qty' => 1]);
        $connection = DB::connection('accounting');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $result = $this->listTool()->__invoke(vendor_organization_id: $vendor->getId());
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        $listed = collect($result['purchase_orders'])->keyBy('order_number');
        $this->assertSame(2, $result['count']);
        $this->assertSame(11, $listed[$first['order_number']]['open_line_count']);
        $this->assertCount(10, $listed[$first['order_number']]['open_lines']);
        $this->assertNotContains('Item 1', array_column($listed[$first['order_number']]['open_lines'], 'description'));
        $this->assertSame(1, $listed[$second['order_number']]['open_line_count']);
        $this->assertCount(1, array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'from `purchase_order_lines`')
        ));
    }

    public function test_finding_an_order_does_not_read_another_companys_account_number(): void
    {
        $foreign = Account::query()->fromApp($this->kanvasApp)->fromCompany($this->company)->first()->replicate();
        $foreign->companies_id = $this->company->getId() + 987654;
        $foreign->account_number = 'FOREIGN-' . uniqid();
        $foreign->is_system = false;
        $foreign->save();
        $vendor = $this->seedTestOrganization('Foreign Account Vendor');
        $created = $this->createTool()
            ->__invoke($vendor->getId(), 'USD', [['description' => 'Item', 'quantity' => 1, 'unit_cost' => 5]]);
        $order = PurchaseOrder::query()
            ->fromApp($this->kanvasApp)
            ->fromCompany($this->company)
            ->where('order_number', $created['order_number'])
            ->first();
        $order->lines()->update(['expense_account_id' => $foreign->getId()]);

        $found = $this->findTool()->__invoke(order_number: $created['order_number']);

        $this->assertNull($found['lines'][0]['gl_account']);
    }

    public function test_finding_an_order_loads_its_account_codes_together(): void
    {
        $account = Account::query()->fromApp($this->kanvasApp)->fromCompany($this->company)->first();
        $this->assertNotNull($account);
        $vendor = $this->seedTestOrganization('Batch Account Vendor');
        $created = $this->createTool()->__invoke($vendor->getId(), 'USD', array_fill(
            0,
            10,
            ['description' => 'Item', 'quantity' => 1, 'unit_cost' => 5]
        ));
        $order = PurchaseOrder::query()->fromApp($this->kanvasApp)->fromCompany($this->company)
            ->where('order_number', $created['order_number'])->first();
        $this->assertNotNull($order);
        $order->lines()->update(['expense_account_id' => $account->getId()]);
        $connection = DB::connection('accounting');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $found = $this->findTool()->__invoke(order_number: $created['order_number']);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertSame(array_fill(0, 10, $account->account_number), array_column($found['lines'], 'gl_account'));
        $this->assertSame(array_fill(0, 10, null), array_column($found['lines'], 'subaccount'));
        $this->assertCount(1, array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'from `accounts`')
        ));
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
        $updated = $this->updateTool()->__invoke(order_number: 'PO-999999999');
        $cancelled = $this->cancelTool()->__invoke(order_number: 'PO-999999999');

        $this->assertSame('purchase_order_not_found', $updated['reason']);
        $this->assertSame('purchase_order_not_found', $cancelled['reason']);
    }

    public function test_an_order_that_cannot_be_edited_says_why(): void
    {
        $vendor = $this->seedTestOrganization('Locked Tool Vendor');
        $order = $this->createTool()
            ->__invoke($vendor->getId(), 'USD', [['description' => 'Item', 'quantity' => 1, 'unit_cost' => 5]]);
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
        return new CreatePurchaseOrderTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function updateTool(): UpdatePurchaseOrderTool
    {
        return new UpdatePurchaseOrderTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function cancelTool(): CancelPurchaseOrderTool
    {
        return new CancelPurchaseOrderTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function findTool(): FindPurchaseOrderTool
    {
        return new FindPurchaseOrderTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function listTool(): ListOpenPurchaseOrdersTool
    {
        return new ListOpenPurchaseOrdersTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }
}
