<?php

declare(strict_types=1);

namespace Tests\Scribe\Purchasing;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Purchasing\Actions\LinkBillToPurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\ReceivePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderConfigurationEnum;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderReceipt;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderMatchService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestBill;
use Tests\Traits\CreatesTestPurchaseOrder;

#[Group('serial')]
class PurchaseOrderReceiveAndMatchTest extends ScribeTestCase
{
    use CreatesTestBill;
    use CreatesTestPurchaseOrder;

    private const array ORDERED = [
        ['description' => 'Pallet of paper', 'quantity' => 10, 'unit_cost' => 12.5, 'sku' => 'PAP-1'],
        ['description' => 'Toner', 'quantity' => 2, 'unit_cost' => 40, 'sku' => 'TN-1'],
    ];

    private mixed $originalOverReceipt = null;
    private mixed $originalPriceTolerance = null;

    protected function afterScribeSetUp(): void
    {
        // Settings write through Redis, which DatabaseTransactions never rolls back.
        $this->originalOverReceipt = $this->company->get(PurchaseOrderConfigurationEnum::OVER_RECEIPT_TOLERANCE_PERCENT->value);
        $this->originalPriceTolerance = $this->company->get(PurchaseOrderConfigurationEnum::PRICE_TOLERANCE_PERCENT->value);
    }

    protected function tearDown(): void
    {
        $this->restore(PurchaseOrderConfigurationEnum::OVER_RECEIPT_TOLERANCE_PERCENT, $this->originalOverReceipt);
        $this->restore(PurchaseOrderConfigurationEnum::PRICE_TOLERANCE_PERCENT, $this->originalPriceTolerance);

        parent::tearDown();
    }

    public function test_a_partial_receipt_leaves_the_order_partially_received(): void
    {
        $order = $this->sentOrder();

        $receipt = $this->receive($order, [['line_number' => 1, 'quantity' => 4]], reference: 'Delivery note 77');

        $this->assertStringStartsWith('GR-', $receipt->receipt_number);
        $this->assertSame('Delivery note 77', $receipt->reference);
        $this->assertSame(PurchaseOrderStatusEnum::PARTIALLY_RECEIVED, $order->fresh()->document_status);
        $line = $order->lines()->where('line_number', 1)->first();
        $this->assertSame('4.000000', $line->received_qty);
        $this->assertSame('6.000000', $line->open_qty);
    }

    public function test_receiving_everything_across_receipts_closes_the_order_as_received(): void
    {
        $order = $this->sentOrder();

        $first = $this->receive($order, [['line_number' => 1, 'quantity' => 10]]);
        $second = $this->receive($order->fresh(), [['line_number' => 2, 'quantity' => 2]]);

        $this->assertSame(PurchaseOrderStatusEnum::RECEIVED, $order->fresh()->document_status);
        $this->assertSame((int) substr($first->receipt_number, 3) + 1, (int) substr($second->receipt_number, 3));
        $this->assertSame(2, $order->receipts()->count());
    }

    public function test_more_than_is_open_is_refused_and_nothing_is_recorded(): void
    {
        $order = $this->sentOrder();

        try {
            $this->receive($order, [['line_number' => 1, 'quantity' => 11]]);
            $this->fail('Receiving above the open quantity should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Line 1: 11 received but only 10 is open', $e->getMessage());
        }

        $this->assertSame(0, PurchaseOrderReceipt::query()->where('purchase_order_id', $order->getId())->count());
        $this->assertSame(PurchaseOrderStatusEnum::SENT, $order->fresh()->document_status);
    }

    public function test_the_company_can_allow_a_little_more_than_ordered(): void
    {
        $this->company->set(PurchaseOrderConfigurationEnum::OVER_RECEIPT_TOLERANCE_PERCENT->value, '10');
        $order = $this->sentOrder();

        $this->receive($order, [['line_number' => 1, 'quantity' => 10.8], ['line_number' => 2, 'quantity' => 2]]);

        $line = $order->lines()->where('line_number', 1)->first();
        $this->assertSame('10.800000', $line->received_qty);
        $this->assertSame('0.000000', $line->open_qty);
        $this->assertSame(PurchaseOrderStatusEnum::RECEIVED, $order->fresh()->document_status);
    }

    public function test_goods_cannot_be_received_against_an_order_that_was_not_sent(): void
    {
        $order = $this->createPurchaseOrderFor($this->seedTestOrganization('Unsent Vendor'), self::ORDERED);

        $this->expectException(InvalidPurchaseOrderTransitionException::class);
        $this->expectExceptionMessage('can only be received against a sent order');

        $this->receive($order, [['line_number' => 1, 'quantity' => 1]]);
    }

    public function test_a_bad_receipt_line_is_refused(): void
    {
        $order = $this->sentOrder();

        foreach ([
            [[], 'at least one line'],
            [[['line_number' => 9, 'quantity' => 1]], 'has no line 9'],
            [[['line_number' => 1, 'quantity' => 0]], 'one quantity above zero'],
            [[['line_number' => 1, 'quantity' => 1], ['line_number' => 1, 'quantity' => 2]], 'one quantity above zero'],
        ] as [$lines, $message]) {
            try {
                $this->receive($order, $lines);
                $this->fail("Should refuse: {$message}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_a_bill_for_what_was_ordered_and_received_matches(): void
    {
        $order = $this->sentOrder();
        $this->receive($order, [['line_number' => 1, 'quantity' => 10], ['line_number' => 2, 'quantity' => 2]]);
        $bill = $this->createBillFor($order->vendor, [
            ['description' => 'Pallet of paper', 'quantity' => 10, 'unit_price' => 12.5, 'sku' => 'PAP-1'],
            ['description' => 'Toner', 'quantity' => 2, 'unit_price' => 40, 'sku' => 'tn-1'],
        ]);

        $match = new PurchaseOrderMatchService()->match($order->fresh(), $bill);

        $this->assertTrue($match->matched());
    }

    public function test_a_bill_for_more_than_was_received_does_not_match(): void
    {
        $order = $this->sentOrder();
        $this->receive($order, [['line_number' => 1, 'quantity' => 4]]);
        $bill = $this->createBillFor($order->vendor, [
            ['description' => 'Pallet of paper', 'quantity' => 10, 'unit_price' => 12.5, 'sku' => 'PAP-1'],
        ]);

        $match = new PurchaseOrderMatchService()->match($order->fresh(), $bill);

        $this->assertSame(['line_1_billed_more_than_received'], $match->keys());
        $this->assertSame(['10', '4', '10'], [
            $match->discrepancies[0]['order_value'],
            $match->discrepancies[0]['received_value'],
            $match->discrepancies[0]['billed_value'],
        ]);
    }

    public function test_a_different_price_is_flagged_unless_the_company_tolerates_it(): void
    {
        $order = $this->sentOrder();
        $this->receive($order, [['line_number' => 1, 'quantity' => 10]]);
        $bill = $this->createBillFor($order->vendor, [
            ['description' => 'Pallet of paper', 'quantity' => 10, 'unit_price' => 13.5, 'sku' => 'PAP-1'],
        ]);
        $matcher = new PurchaseOrderMatchService();

        $this->assertSame(['line_1_price'], $matcher->match($order->fresh(), $bill)->keys());

        $this->company->set(PurchaseOrderConfigurationEnum::PRICE_TOLERANCE_PERCENT->value, '10');

        $this->assertTrue($matcher->match($order->fresh(), $bill)->matched());
    }

    public function test_a_received_unit_is_billed_once_when_two_prices_are_both_within_tolerance_of_it(): void
    {
        $toner = ['description' => 'Toner', 'quantity' => 10, 'sku' => 'TN-1'];
        $order = $this->markSent($this->createPurchaseOrderFor(
            $this->seedTestOrganization('Three Prices Vendor'),
            [
                ['unit_cost' => 100] + $toner,
                ['unit_cost' => 110] + $toner,
                ['unit_cost' => 10] + $toner,
            ]
        ));
        $this->receive($order, [
            ['line_number' => 1, 'quantity' => 10],
            ['line_number' => 2, 'quantity' => 10],
            ['line_number' => 3, 'quantity' => 10],
        ]);
        $this->company->set(PurchaseOrderConfigurationEnum::PRICE_TOLERANCE_PERCENT->value, '10');
        $overlapping = $this->createBillFor($order->vendor, [
            ['unit_price' => 110, 'quantity' => 20] + $toner,
            ['unit_price' => 120] + $toner,
        ]);
        $fitting = $this->createBillFor($order->vendor, [
            ['unit_price' => 105] + $toner,
            ['unit_price' => 120] + $toner,
        ]);

        $match = new PurchaseOrderMatchService()->match($order->fresh(), $overlapping);

        $this->assertSame(['line_2_billed_more_than_received_at_price'], $match->keys());
        $this->assertSame(['10', '20'], [
            $match->discrepancies[0]['received_value'],
            $match->discrepancies[0]['billed_value'],
        ]);
        $this->assertTrue(new PurchaseOrderMatchService()->match($order->fresh(), $fitting)->matched());
    }

    public function test_a_line_that_is_not_on_the_order_and_another_vendor_are_flagged(): void
    {
        $order = $this->sentOrder();
        $bill = $this->createBillFor($this->seedTestOrganization('Other Vendor'), [
            ['description' => 'Something else', 'quantity' => 1, 'unit_price' => 5],
        ]);

        $this->assertSame(
            ['vendor', 'line_1_not_on_order'],
            new PurchaseOrderMatchService()->match($order, $bill)->keys()
        );
    }

    public function test_a_line_without_a_sku_is_paired_by_its_description(): void
    {
        $order = $this->createPurchaseOrderFor(
            $this->seedTestOrganization('Description Vendor'),
            [['description' => 'Cleaning service', 'quantity' => 1, 'unit_cost' => 300]]
        );
        $this->markSent($order);
        $this->receive($order, [['line_number' => 1, 'quantity' => 1]]);
        $bill = $this->createBillFor($order->vendor, [
            ['description' => ' cleaning SERVICE ', 'quantity' => 1, 'unit_price' => 300],
        ]);

        $this->assertTrue(new PurchaseOrderMatchService()->match($order->fresh(), $bill)->matched());
    }

    public function test_a_second_bill_for_goods_already_billed_does_not_match(): void
    {
        $order = $this->sentOrder();
        $this->receive($order, [['line_number' => 1, 'quantity' => 10]]);
        $line = ['description' => 'Pallet of paper', 'quantity' => 10, 'unit_price' => 12.5, 'sku' => 'PAP-1'];
        new LinkBillToPurchaseOrderAction($this->createBillFor($order->vendor, [$line]), $order->fresh())->execute();
        $again = $this->createBillFor($order->vendor, [$line]);

        $match = new PurchaseOrderMatchService()->match($order->fresh(), $again);

        $this->assertSame(['line_1_billed_more_than_received'], $match->keys());
        $this->assertSame('20', $match->discrepancies[0]['billed_value']);
        $this->assertStringContainsString('already on other bills', $match->discrepancies[0]['message']);
    }

    public function test_a_receipt_from_a_copy_loaded_earlier_counts_what_arrived_meanwhile(): void
    {
        $order = $this->sentOrder();
        $stale = $order->fresh();
        $this->receive($order, [['line_number' => 1, 'quantity' => 10]]);

        try {
            $this->receive($stale, [['line_number' => 1, 'quantity' => 10]]);
            $this->fail('The second receipt should see that nothing is open on the line.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('only 0 is open', $e->getMessage());
        }

        $this->assertSame(1, PurchaseOrderReceipt::query()->where('purchase_order_id', $order->getId())->count());
        $this->assertSame('10.000000', (string) $order->lines()->where('line_number', 1)->value('received_qty'));
    }

    public function test_a_matching_bill_is_linked_and_one_that_does_not_match_is_refused(): void
    {
        $order = $this->sentOrder();
        $this->receive($order, [['line_number' => 1, 'quantity' => 10]]);
        $good = $this->createBillFor($order->vendor, [
            ['description' => 'Pallet of paper', 'quantity' => 10, 'unit_price' => 12.5, 'sku' => 'PAP-1'],
        ]);
        $bad = $this->createBillFor($order->vendor, [
            ['description' => 'Pallet of paper', 'quantity' => 12, 'unit_price' => 12.5, 'sku' => 'PAP-1'],
        ]);

        $linked = new LinkBillToPurchaseOrderAction($good, $order->fresh())->execute();

        $this->assertSame($order->getId(), $linked->purchase_order_id);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('line_1_billed_more_than_received');

        new LinkBillToPurchaseOrderAction($bad, $order->fresh())->execute();
    }

    private function sentOrder(): PurchaseOrder
    {
        $order = $this->createPurchaseOrderFor($this->seedTestOrganization('Receiving Vendor'), self::ORDERED);

        return $this->markSent($order);
    }

    private function markSent(PurchaseOrder $order): PurchaseOrder
    {
        $order->document_status = PurchaseOrderStatusEnum::SENT;
        $order->save();

        return $order;
    }

    /**
     * @param list<array{line_number: int, quantity: float|int}> $lines
     */
    private function receive(PurchaseOrder $order, array $lines, ?string $reference = null): PurchaseOrderReceipt
    {
        return new ReceivePurchaseOrderAction(
            order: $order,
            user: static::$cachedUser,
            receivedOn: Carbon::parse('2026-12-02'),
            lines: $lines,
            reference: $reference,
        )->execute();
    }

    private function restore(PurchaseOrderConfigurationEnum $setting, mixed $original): void
    {
        $original === null ? $this->company->del($setting->value) : $this->company->set($setting->value, $original);
    }
}
