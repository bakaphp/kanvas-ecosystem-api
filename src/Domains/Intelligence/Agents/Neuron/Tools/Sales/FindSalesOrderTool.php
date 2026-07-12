<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Sales;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Souk\Orders\Models\Order;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Looks up ONE sales order (customer order) by its number — the receivables/commerce side, distinct
 * from a purchase order (what we buy from vendors). Full header + line items. Reads synced Souk
 * orders; reports found=false when the order isn't in Kanvas.
 */
#[AgentTool(name: 'Find Sales Order')]
class FindSalesOrderTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'find_sales_order',
            description: 'Look up a single sales order (a CUSTOMER order — what a customer bought from us) by its '
                . 'number: customer, status, fulfillment/payment status, total, and line items (product, sku, '
                . 'quantity ordered/fulfilled, unit price). Use this for a specific sales-order number. Returns '
                . 'found=false when it is not in the synced data.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'order_number',
                type: PropertyType::STRING,
                description: 'The sales-order number to look up.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number): array
    {
        $app = $this->app;
        $company = $this->company;

        /** @var Order|null $order */
        $order = Order::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('order_number', trim($order_number))
            ->where('is_deleted', false)
            ->first();

        if ($order === null) {
            return [
                'found' => false,
                'order_number' => $order_number,
                'message' => 'No sales order with that number in the synced data — it may not have been synced yet.',
            ];
        }

        $people = $order->people;
        $customer = $people !== null ? trim($people->firstname . ' ' . $people->lastname) : null;

        return [
            'found' => true,
            'order_number' => $order->order_number,
            'customer' => $customer !== '' ? $customer : ($order->user_email ?: null),
            'customer_email' => $order->user_email,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'fulfillment_status' => $order->fulfillment_status,
            'currency' => $order->currency,
            'total' => (float) $order->total_gross_amount,
            'order_date' => $order->created_at?->toDateString(),
            'reference' => $order->reference,
            'items' => $order->items()->get()->map(fn ($i): array => [
                'product' => $i->product_name,
                'sku' => $i->product_sku,
                'quantity' => (float) $i->quantity,
                'quantity_fulfilled' => (float) $i->quantity_fulfilled,
                'unit_price' => (float) $i->unit_price_gross_amount,
            ])->all(),
        ];
    }
}
