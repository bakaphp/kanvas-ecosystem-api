<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindBillTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindPurchaseOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindVendorTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenBillsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListOpenPurchaseOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryApAgingTool;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderLine;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class AccountsPayableAgentToolsTest extends ScribeTestCase
{
    private function receiveOpenBill(Organization $vendor, float $amount, string $dueDate): void
    {
        $bill = new CreateBillAction(
            new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Materials',
                        quantity: 1,
                        unit_price_native: $amount,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-01'),
                due_date: Carbon::parse($dueDate),
            ),
            static::$cachedUser,
        )->execute();

        new ReceiveBillAction($bill, $vendor, static::$cachedUser)->execute();
    }

    public function test_ap_aging_and_open_bills_tools_report_owed_amounts(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receiveOpenBill($vendor, 800.0, '2026-05-20'); // overdue

        $aging = new QueryApAgingTool()->__invoke(as_of: '2026-06-01');
        $this->assertGreaterThanOrEqual(800.0, (float) $aging['grand_total']);
        $this->assertGreaterThanOrEqual(1, (int) $aging['vendor_count']);

        $open = new ListOpenBillsTool()->__invoke();
        $this->assertGreaterThanOrEqual(1, (int) $open['count']);
        $this->assertGreaterThanOrEqual(800.0, (float) $open['total_balance_due']);
    }

    public function test_list_open_purchase_orders_tool_returns_open_pos(): void
    {
        $po = PurchaseOrder::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'order_type' => 'RO',
            'order_number' => 'PO900',
            'vendor_code' => 'V0000505',
            'status' => 'N',
            'currency' => 'USD',
            'order_total' => 1000,
            'source' => 'acumatica',
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'line_number' => 1,
            'sku' => 'RL-KP336',
            'description' => 'Cooler',
            'open_qty' => 5,
            'unit_cost' => 200,
        ]);

        $result = new ListOpenPurchaseOrdersTool()->__invoke(vendor_code: 'V0000505');

        $this->assertSame(1, (int) $result['count']);
        $this->assertSame('PO900', $result['purchase_orders'][0]['order_number']);
        $this->assertSame(1, (int) $result['purchase_orders'][0]['open_line_count']);
    }

    public function test_find_vendor_tool_returns_acumatica_code(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $vendor->set(CustomFieldEnum::VENDOR_ID->value, 'V0000505');

        $result = new FindVendorTool()->__invoke(name: 'Globex Supply Co');

        $this->assertGreaterThanOrEqual(1, (int) $result['count']);
        $codes = array_column($result['vendors'], 'acumatica_vendor_code');
        $this->assertContains('V0000505', $codes);
    }

    public function test_find_purchase_order_returns_full_detail_or_not_found(): void
    {
        $po = PurchaseOrder::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'order_type' => 'RO',
            'order_number' => 'PO4242',
            'vendor_code' => 'V0000505',
            'status' => 'N',
            'currency' => 'USD',
            'order_total' => 1200,
            'source' => 'acumatica',
        ]);
        PurchaseOrderLine::create([
            'purchase_order_id' => $po->id,
            'line_number' => 1,
            'sku' => 'RL-KP336',
            'description' => 'Cooler',
            'open_qty' => 6,
            'unit_cost' => 200,
        ]);

        $found = new FindPurchaseOrderTool()->__invoke(order_number: 'PO4242');
        $this->assertTrue($found['found']);
        $this->assertSame('V0000505', $found['vendor_code']);
        $this->assertCount(1, $found['lines']);
        $this->assertSame('RL-KP336', $found['lines'][0]['sku']);

        $missing = new FindPurchaseOrderTool()->__invoke(order_number: 'DOES-NOT-EXIST');
        $this->assertFalse($missing['found']);
    }

    public function test_find_bill_returns_full_detail(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receiveOpenBill($vendor, 640.0, '2026-06-20');

        $bill = \Kanvas\Scribe\Bills\Models\Bill::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->latest('id')
            ->first();

        $result = new FindBillTool()->__invoke(bill_number: (string) $bill->bill_number);

        $this->assertTrue($result['found']);
        $this->assertSame('received', $result['document_status']);
        $this->assertSame(640.0, (float) $result['balance_due_native']);
        $this->assertNotEmpty($result['lines']);
    }
}
