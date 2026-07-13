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
 * Lists open sales orders (customer orders not yet completed/cancelled) — the receivables/commerce
 * pipeline. Optionally filtered to one customer by name or email.
 */
#[AgentTool(name: 'List Open Sales Orders')]
class ListOpenSalesOrdersTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_open_sales_orders',
            description: 'Lists open sales orders (customer orders that are not completed or cancelled) with '
                . 'customer, status, fulfillment status and total. Use this for "what orders are open", a '
                . 'customer\'s in-flight orders, or the sales pipeline. Filter by customer name/email when named.',
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
                name: 'customer',
                type: PropertyType::STRING,
                description: 'Filter to a customer by (partial) name or email. Omit for all customers.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max orders to return. Defaults to 25, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $customer = null, ?int $limit = null): array
    {
        $app = $this->app;
        $company = $this->company;
        $limit = max(1, min(100, $limit ?? 25));

        $orders = Order::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->whereNotIn('status', ['completed', 'canceled', 'cancelled', 'voided'])
            ->when($customer !== null && $customer !== '', function ($q) use ($customer): void {
                $q->where(function ($sub) use ($customer): void {
                    $sub->where('user_email', 'like', '%' . $customer . '%')
                        ->orWhereHas('people', function ($p) use ($customer): void {
                            $p->where('firstname', 'like', '%' . $customer . '%')
                                ->orWhere('lastname', 'like', '%' . $customer . '%');
                        });
                });
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return [
            'count' => $orders->count(),
            'orders' => $orders->map(function (Order $order): array {
                $people = $order->people;
                $name = $people !== null ? trim($people->firstname . ' ' . $people->lastname) : '';

                return [
                    'order_number' => $order->order_number,
                    'customer' => $name !== '' ? $name : ($order->user_email ?: null),
                    'status' => $order->status,
                    'fulfillment_status' => $order->fulfillment_status,
                    'currency' => $order->currency,
                    'total' => (float) $order->total_gross_amount,
                    'order_date' => $order->created_at?->toDateString(),
                ];
            })->all(),
        ];
    }
}
