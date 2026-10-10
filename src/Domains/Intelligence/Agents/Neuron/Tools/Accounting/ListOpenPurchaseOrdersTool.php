<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Lists open purchase orders from the Scribe purchasing ledger, optionally scoped to one vendor
 * organization.
 */
#[AgentTool(name: 'List Open Purchase Orders', category: 'accounting')]
class ListOpenPurchaseOrdersTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'list_open_purchase_orders';

    protected ?string $description = 'Lists open (non-closed) purchase orders with their vendor, status, total and open '
        . 'line items (sku, open quantity, unit cost, GL coding). Use this to see what a vendor has on '
        . 'order, or to find the PO an incoming invoice should match against. Filter by '
        . 'vendor_organization_id when the user names a specific vendor.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'vendor_organization_id',
                type: PropertyType::INTEGER,
                description: 'Kanvas organization id of the vendor to filter to. Omit for all vendors.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max purchase orders to return. Defaults to 20, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $vendor_organization_id = null, ?int $limit = null): array
    {
        $app = $this->app;
        $company = $this->company;
        $limit = max(1, min(100, $limit ?? 20));

        $query = PurchaseOrder::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->when($vendor_organization_id !== null, fn ($q) => $q->where('vendor_organization_id', $vendor_organization_id))
            ->orderByDesc('order_date')
            ->limit($limit);

        $orders = $query->get()->map(function (PurchaseOrder $po): array {
            $lines = $po->lines()->where('open_qty', '>', 0)->get();

            return [
                'order_number' => $po->order_number,
                'order_type' => $po->order_type,
                'vendor_organization_id' => $po->vendor_organization_id,
                'status' => $po->status,
                'order_date' => $po->order_date?->toDateString(),
                'currency' => $po->currency,
                'order_total' => (float) $po->order_total,
                'open_line_count' => $lines->count(),
                'open_lines' => $lines->take(10)->map(fn ($l): array => [
                    'sku' => $l->sku,
                    'description' => $l->description,
                    'open_qty' => (float) $l->open_qty,
                    'unit_cost' => (float) $l->unit_cost,
                ])->all(),
            ];
        })->all();

        return [
            'vendor_organization_id' => $vendor_organization_id,
            'count' => count($orders),
            'purchase_orders' => $orders,
        ];
    }
}
