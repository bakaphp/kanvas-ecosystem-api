<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Tests\Scribe\ScribeTestCase;

class PurchaseOrderGraphQLTest extends ScribeTestCase
{
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

    private function orderNumber(string $id): string
    {
        return PurchaseOrder::query()->findOrFail($id)->order_number;
    }
}
