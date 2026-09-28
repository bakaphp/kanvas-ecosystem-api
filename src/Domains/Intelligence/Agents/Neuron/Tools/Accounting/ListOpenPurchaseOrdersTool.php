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
 * Lists open purchase orders (mirrored from the ERP) — the reference the AP agent matches an
 * incoming vendor invoice against. Optionally filtered to one vendor.
 */
#[AgentTool(name: 'List Open Purchase Orders', category: 'accounting')]
class ListOpenPurchaseOrdersTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_open_purchase_orders',
            description: 'Lists open (non-closed) purchase orders with their vendor, status, total and open '
                . 'line items (sku, open quantity, unit cost, GL coding). Use this to see what a vendor has on '
                . 'order, or to find the PO an incoming invoice should match against. Filter by vendor_code when '
                . 'the user names a specific vendor.',
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
                name: 'vendor_code',
                type: PropertyType::STRING,
                description: 'Acumatica vendor code (e.g. V0000505) to filter to one vendor. Omit for all vendors.',
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
    public function __invoke(?string $vendor_code = null, ?int $limit = null): array
    {
        $app = $this->app;
        $company = $this->company;
        $limit = max(1, min(100, $limit ?? 20));

        $query = PurchaseOrder::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->when($vendor_code !== null && $vendor_code !== '', fn ($q) => $q->where('vendor_code', $vendor_code))
            ->orderByDesc('order_date')
            ->limit($limit);

        $orders = $query->get()->map(function (PurchaseOrder $po): array {
            $lines = $po->lines()->where('open_qty', '>', 0)->get();

            return [
                'order_number' => $po->order_number,
                'order_type' => $po->order_type,
                'vendor_code' => $po->vendor_code,
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
            'vendor_code' => $vendor_code,
            'count' => count($orders),
            'purchase_orders' => $orders,
        ];
    }
}
