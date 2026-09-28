<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Sales;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTenantRecordForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Souk\Orders\Models\Order;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Looks up ONE sales order (customer order) by its number — the receivables/commerce side, distinct
 * from a purchase order (what we buy from vendors). Full header + line items. Reads synced Souk
 * orders; reports found=false when the order isn't in Kanvas.
 */
#[AgentTool(name: 'Find Sales Order', category: 'commerce')]
class FindSalesOrderTool extends Tool implements HasRunKey
{
    use FindsTenantRecordForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_sales_order',
            description: 'Look up a single sales order (a CUSTOMER order — what a customer bought from us) by its '
                . 'number: customer, status, fulfillment/payment status, total, line items (product, sku, '
                . 'quantity ordered/fulfilled, unit price), and any affiliate commission recorded for the order '
                . '(affiliate, commission amount/rate/type, status). Use this for a specific sales-order number, '
                . 'including "does this order have an affiliate commission". An empty affiliate_commissions list '
                . 'means the order truly has none. Returns found=false when it is not in the synced data.',
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
        $result = $this->findTenantRecordOrNotFound(
            Order::class,
            'order_number',
            $order_number,
            'sales order'
        );

        if (is_array($result)) {
            return $result;
        }

        /** @var Order $order */
        $order = $result;

        $people = $order->people;
        $customer = $people !== null ? trim($people->firstname . ' ' . $people->lastname) : null;

        $affiliateCommissions = $order->affiliateConversion()
            ->with('affiliate')
            ->get()
            ->map(fn ($conversion): array => [
                'affiliate' => $conversion->affiliate?->name,
                'affiliate_code' => $conversion->affiliate?->unique_identifier,
                'commission_amount' => (float) $conversion->commission_amount,
                'commission_rate' => (float) $conversion->commission_rate,
                'commission_type' => $conversion->commission_type,
                'status' => $conversion->status,
            ])->all();

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
            'affiliate_commissions' => $affiliateCommissions,
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
