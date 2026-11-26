<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\DescribesSalesOrderFields;
use Kanvas\Scribe\SalesOrders\Actions\UpdateSalesOrderAction;
use Kanvas\Scribe\SalesOrders\DataTransferObject\SalesOrder as SalesOrderData;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Update Customer Order', category: 'accounting')]
class UpdateCustomerOrderTool extends AbstractCustomerOrderTool
{
    use DescribesSalesOrderFields;

    protected string $name = 'update_customer_order';

    protected ?string $description = 'Corrects a sales order that is still received, or that Operations returned: its '
        . 'lines, currency, customer PO number and date, Incoterm, consignee, payment term, requested date, signature '
        . 'checks, notes, or the quote it is reviewed against. Only what is sent changes, and sending lines replaces '
        . 'all of them. Correcting a returned order puts it back to received, and any correction voids the last review, '
        . 'so run review_customer_po again. A confirmed order cannot be edited. Use it only when the person gives the '
        . 'correction.';

    /**
     * @return array<int, ToolProperty|ArrayProperty|ObjectProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'order_number',
                type: PropertyType::STRING,
                description: 'The sales order number, e.g. SO-000012.',
                required: true,
            ),
            new ToolProperty(
                name: 'quote_number',
                type: PropertyType::STRING,
                description: 'The number of our quote or proforma to review the order against, when the order has '
                    . 'none or the wrong one.',
                required: false,
            ),
            ...$this->salesOrderFieldProperties(
                'The full corrected list of lines. It replaces the current lines, so include every line that stays.'
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>>|null $lines
     * @param array<string, bool>|null $signature_checklist
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $order_number,
        ?string $quote_number = null,
        ?string $customer_po_number = null,
        ?string $customer_po_date = null,
        ?string $currency = null,
        ?array $lines = null,
        ?string $incoterm = null,
        ?string $consignee = null,
        ?int $payment_terms_days = null,
        ?string $requested_date = null,
        ?array $signature_checklist = null,
        ?string $notes = null,
    ): array {
        $quote = $this->quoteByNumber($quote_number, 'updated');

        if (is_array($quote)) {
            return $quote;
        }

        $changes = array_filter([
            'quote_id' => $quote?->getId(),
            'customer_po_number' => $customer_po_number,
            'customer_po_date' => $customer_po_date,
            'currency' => $currency,
            'lines' => $lines,
            'incoterm' => $incoterm,
            'consignee' => $consignee,
            'payment_terms_days' => $payment_terms_days,
            'requested_date' => $requested_date,
            'signature_checklist' => $signature_checklist,
            'notes' => $notes,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->actOn(
            $order_number,
            'updated',
            fn (SalesOrder $order): SalesOrder => new UpdateSalesOrderAction(
                $order,
                SalesOrderData::forUpdate(
                    $order,
                    $this->app,
                    $this->user,
                    $this->company,
                    $changes
                )
            )->execute()
        );
    }
}
