<?php

declare(strict_types=1);

namespace Tests\Scribe\Purchasing;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Purchasing\Actions\CancelPurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\CreatePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Actions\UpdatePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrder as PurchaseOrderData;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderStateMachineService;
use Tests\Scribe\ScribeTestCase;

class PurchaseOrderTest extends ScribeTestCase
{
    public function test_an_order_starts_as_a_numbered_draft_with_its_total_from_the_lines(): void
    {
        $order = $this->createOrder([
            ['description' => 'Pallet of paper', 'quantity' => 10, 'unit_cost' => 12.5],
            ['description' => 'Toner', 'quantity' => 2, 'unit_cost' => 40, 'sku' => 'TN-1'],
        ]);

        $this->assertStringStartsWith('PO-', $order->order_number);
        $this->assertSame(PurchaseOrderStatusEnum::DRAFT, $order->document_status);
        $this->assertSame(PurchaseOrder::SOURCE_KANVAS, $order->source);
        $this->assertSame('USD', $order->currency);
        $this->assertSame('205.0000', $order->order_total);
        $this->assertCount(2, $order->lines);
        $this->assertSame([1, 2], $order->lines->pluck('line_number')->all());
        $this->assertSame('10.000000', $order->lines->first()->open_qty);
        $this->assertSame('0.000000', $order->lines->first()->received_qty);
    }

    public function test_each_order_spends_the_next_number(): void
    {
        $first = $this->createOrder();
        $second = $this->createOrder();

        $this->assertNotSame($first->order_number, $second->order_number);
        $this->assertSame(
            (int) substr($first->order_number, 3) + 1,
            (int) substr($second->order_number, 3)
        );
    }

    public function test_an_order_without_a_usable_line_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at least one line');

        $this->createOrder([]);
    }

    public function test_a_line_with_no_quantity_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('quantity above zero');

        $this->createOrder([['description' => 'Nothing', 'quantity' => 0, 'unit_cost' => 5]]);
    }

    public function test_editing_a_draft_replaces_the_lines_and_keeps_the_rest(): void
    {
        $order = $this->createOrder(notes: 'Rush');

        $updated = $this->update($order, [
            'lines' => [['description' => 'Chairs', 'quantity' => 4, 'unit_cost' => 75.25]],
            'expected_date' => '2026-12-15',
        ]);

        $this->assertSame($order->order_number, $updated->order_number);
        $this->assertSame('Rush', $updated->notes);
        $this->assertSame('2026-12-15', $updated->expected_date->toDateString());
        $this->assertSame('301.0000', $updated->order_total);
        $this->assertSame(['Chairs'], $updated->lines->pluck('description')->all());
    }

    public function test_an_order_that_left_draft_cannot_be_edited(): void
    {
        $order = $this->createOrder();
        $order->document_status = PurchaseOrderStatusEnum::SENT;
        $order->save();

        $this->expectException(InvalidPurchaseOrderTransitionException::class);
        $this->expectExceptionMessage('only a draft can be edited');

        $this->update($order, ['notes' => 'Too late']);
    }

    public function test_an_order_mirrored_from_an_erp_is_read_only(): void
    {
        $mirrored = $this->createOrder();
        $mirrored->document_status = null;
        $mirrored->source = 'external-erp';
        $mirrored->save();

        $this->expectException(InvalidPurchaseOrderTransitionException::class);
        $this->expectExceptionMessage('comes from an external system');

        new CancelPurchaseOrderAction($mirrored)->execute();
    }

    public function test_editing_a_mirrored_order_with_no_linked_vendor_is_refused_not_a_crash(): void
    {
        $mirrored = $this->createOrder();
        $mirrored->document_status = null;
        $mirrored->source = 'external-erp';
        $mirrored->vendor_organization_id = null;
        $mirrored->save();

        $this->expectException(InvalidPurchaseOrderTransitionException::class);
        $this->expectExceptionMessage('comes from an external system');

        $this->update($mirrored, ['notes' => 'Should not land']);
    }

    public function test_sending_an_empty_expected_date_clears_it(): void
    {
        $order = $this->update($this->createOrder(), ['expected_date' => '2026-12-15']);

        $cleared = $this->update($order, ['expected_date' => null]);

        $this->assertNull($cleared->expected_date);
    }

    public function test_cancelling_records_when_and_why_and_a_second_cancel_changes_nothing(): void
    {
        $order = $this->createOrder();

        $cancelled = new CancelPurchaseOrderAction($order, '  Vendor discontinued the item ')->execute();
        $stampedAt = $cancelled->cancelled_at;
        $again = new CancelPurchaseOrderAction($cancelled, 'Another reason')->execute();

        $this->assertSame(PurchaseOrderStatusEnum::CANCELLED, $again->document_status);
        $this->assertSame('Vendor discontinued the item', $again->cancel_reason);
        $this->assertEquals($stampedAt, $again->cancelled_at);
    }

    public function test_an_order_with_goods_received_cannot_be_cancelled(): void
    {
        $order = $this->createOrder();
        $order->document_status = PurchaseOrderStatusEnum::PARTIALLY_RECEIVED;
        $order->save();

        $this->expectException(InvalidPurchaseOrderTransitionException::class);

        new CancelPurchaseOrderAction($order)->execute();
    }

    public function test_the_lifecycle_only_moves_forward(): void
    {
        $machine = new PurchaseOrderStateMachineService();

        $this->assertTrue($machine->canTransition(PurchaseOrderStatusEnum::DRAFT, PurchaseOrderStatusEnum::APPROVED));
        $this->assertTrue($machine->canTransition(PurchaseOrderStatusEnum::SENT, PurchaseOrderStatusEnum::RECEIVED));
        $this->assertFalse($machine->canTransition(PurchaseOrderStatusEnum::DRAFT, PurchaseOrderStatusEnum::RECEIVED));
        $this->assertFalse($machine->canTransition(PurchaseOrderStatusEnum::RECEIVED, PurchaseOrderStatusEnum::DRAFT));
        $this->assertFalse($machine->canTransition(PurchaseOrderStatusEnum::CANCELLED, PurchaseOrderStatusEnum::DRAFT));
    }

    /**
     * @param list<array<string, mixed>>|null $lines
     */
    private function createOrder(?array $lines = null, ?string $notes = null): PurchaseOrder
    {
        $vendor = $this->seedTestOrganization('Test Vendor');

        return new CreatePurchaseOrderAction(
            PurchaseOrderData::from(
                $this->kanvasApp,
                static::$cachedUser,
                $this->company,
                [
                    'vendor_id' => $vendor->getId(),
                    'currency' => 'USD',
                    'notes' => $notes,
                    'lines' => $lines ?? [['description' => 'Default item', 'quantity' => 1, 'unit_cost' => 10]],
                ]
            )
        )->execute();
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function update(PurchaseOrder $order, array $changes): PurchaseOrder
    {
        return new UpdatePurchaseOrderAction(
            $order,
            PurchaseOrderData::forUpdate(
                $order,
                $this->kanvasApp,
                static::$cachedUser,
                $this->company,
                $changes
            ),
        )->execute();
    }
}
