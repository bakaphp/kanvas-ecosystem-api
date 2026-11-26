<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestSalesOrder;

class SalesOrderGraphQLTest extends ScribeTestCase
{
    use CreatesTestSalesOrder;

    private const string REGISTER = '
        mutation ($input: ScribeCustomerPoInput!) {
            registerScribeCustomerPo(input: $input) {
                id order_number document_status po_source currency order_total customer_po_number
                customer { id }
                lines { line_number description quantity unit_price line_total }
            }
        }';

    public function test_an_order_goes_through_both_gates_through_the_api(): void
    {
        $customer = $this->seedTestOrganization('Graph Customer');
        $quote = $this->createTestQuote($customer);

        $registered = $this->graphQL(self::REGISTER, ['input' => [
            'customer_id' => $customer->getId(),
            'po_source' => 'CUSTOMER_PO',
            'quote_id' => $quote->getId(),
            'customer_po_number' => 'PO-7001',
            'customer_po_date' => '2026-11-20',
            'currency' => 'usd',
            'incoterm' => 'FOB',
            'signature_checklist' => ['authorized_signer' => true],
            'lines' => [
                ['description' => 'Pallet of rice', 'quantity' => 10, 'unit_price' => 20, 'sku' => 'RICE-1'],
                ['description' => 'Pallet of beans', 'quantity' => 5, 'unit_price' => 30, 'sku' => 'BEAN-1'],
            ],
        ]])->assertSuccessful()->json('data.registerScribeCustomerPo');

        $this->assertSame('RECEIVED', $registered['document_status']);
        $this->assertSame('CUSTOMER_PO', $registered['po_source']);
        $this->assertStringStartsWith('SO-', $registered['order_number']);
        $this->assertSame((string) $customer->getId(), $registered['customer']['id']);

        $id = $registered['id'];

        $this->graphQL('
            mutation ($id: ID!) {
                reviewScribeSalesOrder(id: $id, agreement: { incoterm: "CIF" }) {
                    differences { key po_value quote_value resolution }
                }
            }', ['id' => $id])
            ->assertJsonPath('data.reviewScribeSalesOrder.differences.0.key', 'incoterm');

        $this->graphQL('
            mutation ($id: ID!) {
                confirmScribeSalesOrder(id: $id, feasibility_confirmed: true) { id }
            }', ['id' => $id])
            ->assertGraphQLErrorMessage('Clarify every difference in writing before confirming. Still open: incoterm.');

        $this->graphQL('
            mutation ($id: ID!) {
                resolveScribeSalesOrderDifference(id: $id, key: "incoterm", resolution: "Agreed to FOB by email") {
                    differences { resolution }
                }
            }', ['id' => $id])
            ->assertJsonPath('data.resolveScribeSalesOrderDifference.differences.0.resolution', 'Agreed to FOB by email');

        $this->graphQL('
            mutation ($id: ID!) {
                confirmScribeSalesOrder(id: $id, feasibility_confirmed: true) { document_status confirmed_at }
            }', ['id' => $id])
            ->assertJsonPath('data.confirmScribeSalesOrder.document_status', 'CONFIRMED');

        $this->graphQL('
            mutation ($id: ID!) {
                acceptScribeSalesOrder(id: $id, etd: "2026-12-10", eta: "2026-12-28") { document_status etd eta }
            }', ['id' => $id])
            ->assertJsonPath('data.acceptScribeSalesOrder.document_status', 'ACCEPTED')
            ->assertJsonPath('data.acceptScribeSalesOrder.eta', '2026-12-28');

        $this->graphQL('
            query {
                scribeSalesOrders(where: { column: DOCUMENT_STATUS, value: "accepted" }) { data { id } }
            }')
            ->assertSuccessful()
            ->assertJsonFragment(['id' => $id]);

        $this->graphQL('
            query {
                scribeSalesOrders(
                    where: { column: ACCEPTED_AT, operator: GTE, value: "2000-01-01 00:00:00" }
                    orderBy: [{ column: ETD, order: DESC }]
                ) { data { id } }
            }')
            ->assertSuccessful()
            ->assertJsonFragment(['id' => $id]);
    }

    public function test_operations_can_return_a_confirmed_order_with_a_reason(): void
    {
        $customer = $this->seedTestOrganization('Returned Customer');
        $quote = $this->createTestQuote($customer);
        $id = $this->graphQL(self::REGISTER, ['input' => [
            'customer_id' => $customer->getId(),
            'po_source' => 'CUSTOMER_PO',
            'quote_id' => $quote->getId(),
            'customer_po_number' => 'PO-7002',
            'customer_po_date' => '2026-11-20',
            'currency' => 'USD',
            'signature_checklist' => ['authorized_signer' => true],
            'lines' => [
                ['description' => 'Pallet of rice', 'quantity' => 10, 'unit_price' => 20, 'sku' => 'RICE-1'],
                ['description' => 'Pallet of beans', 'quantity' => 5, 'unit_price' => 30, 'sku' => 'BEAN-1'],
            ],
        ]])->json('data.registerScribeCustomerPo.id');

        $this->graphQL('mutation ($id: ID!) { reviewScribeSalesOrder(id: $id) { id } }', ['id' => $id])
            ->assertSuccessful();
        $this->graphQL('mutation ($id: ID!) { confirmScribeSalesOrder(id: $id, feasibility_confirmed: true) { id } }', ['id' => $id])
            ->assertSuccessful();

        $this->graphQL('
            mutation ($id: ID!) {
                returnScribeSalesOrder(id: $id, reason: "No container space") { document_status return_reason }
            }', ['id' => $id])
            ->assertJsonPath('data.returnScribeSalesOrder.document_status', 'RETURNED')
            ->assertJsonPath('data.returnScribeSalesOrder.return_reason', 'No container space');

        $this->graphQL('
            mutation ($id: ID!) {
                cancelScribeSalesOrder(id: $id, reason: "Customer withdrew") { document_status cancel_reason }
            }', ['id' => $id])
            ->assertJsonPath('data.cancelScribeSalesOrder.document_status', 'CANCELLED');
    }

    public function test_an_accepted_order_ships_is_delivered_and_waits_for_its_payment_through_the_api(): void
    {
        $customer = $this->seedTestOrganization('Shipping Graph Customer');
        $order = $this->createAcceptedSalesOrder($customer);
        $invoice = $this->issueInvoiceWithLines($customer, [
            ['quantity' => 10, 'unit_price' => 20],
            ['quantity' => 5, 'unit_price' => 30],
        ]);
        $id = (string) $order->getId();

        $this->graphQL('
            mutation ($id: ID!, $invoice: ID!) {
                checkScribeShippingDocuments(
                    id: $id
                    invoice_id: $invoice
                    packing_list: [{ quantity: 10 }, { quantity: 5 }]
                    bl_draft: { total_quantity: 15 }
                ) {
                    documents_checked_at
                    documents_differences { key }
                    invoice { id }
                }
            }', ['id' => $id, 'invoice' => (string) $invoice->getId()])
            ->assertJsonPath('data.checkScribeShippingDocuments.documents_differences', [])
            ->assertJsonPath('data.checkScribeShippingDocuments.invoice.id', (string) $invoice->getId());

        $this->graphQL('
            mutation ($id: ID!) {
                recordScribeShipment(
                    id: $id
                    bl_number: "BL-9001"
                    bl_date: "2026-12-10"
                    vessel: "Test Vessel"
                    containers: ["CONT-1"]
                    eta: "2026-12-28"
                ) { bl_number due_date documents_package_due_on }
            }', ['id' => $id])
            ->assertJsonPath('data.recordScribeShipment.due_date', '2027-01-29')
            ->assertJsonPath('data.recordScribeShipment.documents_package_due_on', '2026-12-15');

        $this->graphQL('
            mutation ($id: ID!) {
                markScribeDocumentsPackageSent(id: $id) { documents_package_sent_at }
            }', ['id' => $id])
            ->assertSuccessful();

        $this->graphQL('
            query {
                scribeSalesOrders(where: { column: DOCUMENT_STATUS, value: "shipped" }) {
                    data { id shipment { bl_number } collection_plan { day date } }
                }
            }')
            ->assertJsonFragment(['bl_number' => 'BL-9001'])
            ->assertJsonFragment(['day' => 50, 'date' => '2027-01-29']);

        $this->graphQL('
            mutation ($id: ID!) {
                confirmScribeDelivery(id: $id, delivered_on: "2026-12-28", pod_reference: "POD-9001") {
                    pod_reference
                    damage_reported
                }
            }', ['id' => $id])
            ->assertJsonPath('data.confirmScribeDelivery.pod_reference', 'POD-9001')
            ->assertJsonPath('data.confirmScribeDelivery.damage_reported', false);

        $this->graphQL('mutation ($id: ID!) { closeScribeSalesOrder(id: $id) { id } }', ['id' => $id])
            ->assertGraphQLErrorMessage("Sales order {$order->order_number} cannot be closed until its invoice is paid.");
    }
}
