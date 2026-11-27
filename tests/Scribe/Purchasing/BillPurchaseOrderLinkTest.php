<?php

declare(strict_types=1);

namespace Tests\Scribe\Purchasing;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\UpdateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Purchasing\Actions\LinkBillToPurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\ReceivePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderMatchService;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestPurchaseOrder;

class BillPurchaseOrderLinkTest extends ScribeTestCase
{
    use CreatesTestPurchaseOrder;

    private const array ORDERED = ['description' => 'Toner', 'sku' => 'TN-1', 'quantity' => 10, 'unit_cost' => 10];

    public function test_a_bill_created_against_an_order_it_does_not_match_is_saved_without_the_link(): void
    {
        $order = $this->createPurchaseOrderFor($this->seedTestOrganization('Unreceived Order Vendor'), [self::ORDERED]);

        $bill = $this->bill($order, 10, linked: true);

        $this->assertNull($bill->fresh()->purchase_order_id);
        $this->assertStringContainsString(
            "it does not match purchase order {$order->order_number}",
            (string) $bill->fresh()->internal_notes
        );
    }

    public function test_a_bill_created_against_an_order_it_matches_keeps_the_link(): void
    {
        $order = $this->receivedOrder([self::ORDERED]);

        $bill = $this->bill($order, 10, linked: true);

        $this->assertSame($order->getId(), (int) $bill->fresh()->purchase_order_id);
    }

    public function test_editing_a_linked_bill_out_of_the_match_drops_the_link(): void
    {
        $order = $this->receivedOrder([self::ORDERED]);
        $bill = $this->bill($order, 10);
        new LinkBillToPurchaseOrderAction($bill, $order)->execute();

        $edited = new UpdateBillAction(
            $bill,
            BillData::fromMultiple($this->kanvasApp, $this->company, $this->billInput($order, 20, linked: true))
        )->execute();

        $this->assertNull($edited->fresh()->purchase_order_id);
    }

    public function test_one_product_on_two_order_lines_is_matched_as_one_quantity(): void
    {
        $order = $this->receivedOrder([self::ORDERED, self::ORDERED]);

        $this->assertTrue(new PurchaseOrderMatchService()->match($order, $this->bill($order, 20))->matched());
        $this->assertSame(
            ['line_1_billed_more_than_received'],
            new PurchaseOrderMatchService()->match($order, $this->bill($order, 21))->keys()
        );
    }

    public function test_one_product_ordered_at_two_prices_cannot_be_billed_entirely_at_the_higher_one(): void
    {
        $order = $this->receivedOrder([self::ORDERED, ['unit_cost' => 20] + self::ORDERED]);

        $match = new PurchaseOrderMatchService()->match($order, $this->bill($order, 20, unitPrice: 20));

        $this->assertSame(['line_2_billed_more_than_received_at_price'], $match->keys());
        $this->assertSame(['10', '20'], [
            $match->discrepancies[0]['received_value'],
            $match->discrepancies[0]['billed_value'],
        ]);
    }

    public function test_units_that_arrived_at_the_lower_price_cannot_be_billed_at_the_one_not_received(): void
    {
        $order = $this->createPurchaseOrderFor(
            $this->seedTestOrganization('Part Received Vendor'),
            [self::ORDERED, ['unit_cost' => 20] + self::ORDERED]
        );
        $order->document_status = PurchaseOrderStatusEnum::SENT;
        $order->save();
        new ReceivePurchaseOrderAction(
            order: $order,
            user: static::$cachedUser,
            receivedOn: Carbon::today(),
            lines: [['line_number' => 1, 'quantity' => 10]],
        )->execute();

        $this->assertSame(
            ['line_2_billed_more_than_received_at_price'],
            new PurchaseOrderMatchService()->match($order->fresh(), $this->bill($order, 5, unitPrice: 20))->keys()
        );
        $this->assertTrue(
            new PurchaseOrderMatchService()->match($order->fresh(), $this->bill($order, 5))->matched()
        );
    }

    public function test_a_product_named_by_its_code_and_by_a_description_draws_on_the_same_receipts(): void
    {
        $order = $this->receivedOrder([self::ORDERED, ['description' => 'Second delivery'] + self::ORDERED]);
        $bill = new CreateBillAction(
            BillData::fromMultiple($this->kanvasApp, $this->company, [
                'lines' => [
                    $this->billLine(20),
                    ['sku' => null, 'description' => 'Second delivery'] + $this->billLine(10),
                ],
            ] + $this->billInput($order, 0, linked: false))
        )->execute();

        $this->assertSame(
            ['line_1_billed_more_than_received'],
            new PurchaseOrderMatchService()->match($order, $bill)->keys()
        );
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function receivedOrder(array $lines): PurchaseOrder
    {
        $order = $this->createPurchaseOrderFor($this->seedTestOrganization('Received Order Vendor'), $lines);
        $order->document_status = PurchaseOrderStatusEnum::SENT;
        $order->save();

        new ReceivePurchaseOrderAction(
            order: $order,
            user: static::$cachedUser,
            receivedOn: Carbon::today(),
            lines: array_map(
                static fn (int $index): array => ['line_number' => $index + 1, 'quantity' => 10],
                array_keys($lines)
            ),
        )->execute();

        return $order->fresh();
    }

    private function bill(
        PurchaseOrder $order,
        float $quantity,
        bool $linked = false,
        float $unitPrice = 10
    ): Bill {
        $input = ['lines' => [$this->billLine($quantity, $unitPrice)]] + $this->billInput($order, $quantity, $linked);

        return new CreateBillAction(BillData::fromMultiple($this->kanvasApp, $this->company, $input))->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function billLine(float $quantity, float $unitPrice = 10): array
    {
        return [
            'description' => 'Toner',
            'sku' => 'TN-1',
            'quantity' => $quantity,
            'unit_price_native' => $unitPrice,
            'expense_account_id' => $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function billInput(PurchaseOrder $order, float $quantity, bool $linked): array
    {
        return [
            'vendor_organization_id' => $order->vendor_organization_id,
            'purchase_order_id' => $linked ? $order->getId() : null,
            'currency' => 'USD',
            'bill_number' => 'POL-' . uniqid(),
            'bill_date' => Carbon::today()->toDateString(),
            'lines' => [$this->billLine($quantity)],
        ];
    }
}
