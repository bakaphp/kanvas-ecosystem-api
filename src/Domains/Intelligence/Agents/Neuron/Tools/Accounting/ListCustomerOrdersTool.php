<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Lists sales orders, newest first, optionally of one status or one customer. */
#[AgentTool(name: 'List Customer Orders', category: 'accounting')]
class ListCustomerOrdersTool extends AbstractCustomerOrderTool
{
    protected string $name = 'list_customer_orders';

    protected ?string $description = 'Lists the company\'s sales orders, newest first, with their status and total. Filter '
        . 'by status (received, confirmed, accepted, returned, shipped, delivered, closed or cancelled) to see what is '
        . 'waiting on Commercial or on Operations, or by customer.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'Only orders in this status.',
                required: false,
            ),
            new ToolProperty(
                name: 'customer_organization_id',
                type: PropertyType::INTEGER,
                description: 'Only this customer\'s orders.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max orders to return. Defaults to 20, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $status = null, ?int $customer_organization_id = null, ?int $limit = null): array
    {
        $statusFilter = $status === null ? null : SalesOrderStatusEnum::tryFrom(strtolower(trim($status)));

        if ($status !== null && $statusFilter === null) {
            return [
                'reason' => 'unknown_status',
                'message' => "{$status} is not a sales order status.",
            ];
        }

        $orders = SalesOrder::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when($statusFilter !== null, fn ($query) => $query->where('document_status', $statusFilter->value))
            ->when(
                $customer_organization_id !== null,
                fn ($query) => $query->where('customer_organization_id', $customer_organization_id)
            )
            ->orderByDesc('received_at')
            ->limit(max(1, min(100, $limit ?? 20)))
            ->get()
            ->map(fn (SalesOrder $order): array => $this->summary($order))
            ->all();

        return ['count' => count($orders), 'sales_orders' => $orders];
    }
}
