<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Collection;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTenantRecordForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderReceipt;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Looks up ONE purchase order by its number — full header + every line with quantities and GL
 * coding. This is the "pull up PO #X" lookup (vs list_open_purchase_orders which enumerates). Covers
 * orders mirrored from an ERP and orders the company issued.
 */
#[AgentTool(name: 'Find Purchase Order', category: 'accounting')]
class FindPurchaseOrderTool extends Tool
{
    use FindsTenantRecordForTool;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'find_purchase_order';

    protected ?string $description = 'Look up a single purchase order by its number and return the full detail: vendor, '
        . 'status, total, and every line (sku, ordered/open/received quantity, unit cost, GL account + '
        . 'subaccount). Use this when the user names a specific PO number, whether the company issued it or '
        . 'it came from an external system. Returns found=false when no purchase order has that number.';

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
                description: 'The purchase-order number to look up (e.g. 62023099).',
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
            PurchaseOrder::class,
            'order_number',
            $order_number,
            'purchase order'
        );

        if (is_array($result)) {
            return $result;
        }

        /** @var PurchaseOrder $po */
        $po = $result;
        $lines = $po->lines()->orderBy('line_number')->get();
        $accounts = $this->codes(Account::class, $lines->pluck('expense_account_id'), 'account_number');
        $subaccounts = $this->codes(Subaccount::class, $lines->pluck('subaccount_id'), 'sub_code');

        return [
            'found' => true,
            'order_number' => $po->order_number,
            'order_type' => $po->order_type,
            'vendor_organization_id' => $po->vendor_organization_id,
            'status' => $po->document_status?->value ?? $po->status,
            'order_date' => $po->order_date?->toDateString(),
            'currency' => $po->currency,
            'order_total' => (float) $po->order_total,
            'lines' => $lines->map(static fn ($l): array => [
                'line_number' => (int) $l->line_number,
                'sku' => $l->sku,
                'description' => $l->description,
                'order_qty' => (float) $l->order_qty,
                'open_qty' => (float) $l->open_qty,
                'received_qty' => (float) $l->received_qty,
                'unit_cost' => (float) $l->unit_cost,
                'ext_cost' => (float) $l->ext_cost,
                'gl_account' => $accounts[$l->expense_account_id] ?? null,
                'subaccount' => $subaccounts[$l->subaccount_id] ?? null,
            ])->all(),
            'receipts' => $po->receipts()
                ->orderBy('received_on')
                ->orderBy('id')
                ->get()
                ->map(static fn (PurchaseOrderReceipt $receipt): array => [
                    'receipt_number' => $receipt->receipt_number,
                    'received_on' => $receipt->received_on->toDateString(),
                    'reference' => $receipt->reference,
                ])
                ->all(),
        ];
    }

    /**
     * @param class-string $model
     * @param Collection<int, int|null> $ids
     *
     * @return Collection<int, string|null>
     */
    private function codes(string $model, Collection $ids, string $column): Collection
    {
        $ids = $ids->filter()->unique();

        if ($ids->isEmpty()) {
            return new Collection();
        }

        return $model::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->whereIn('id', $ids)
            ->pluck($column, 'id')
            ->map(static fn (mixed $value): ?string => $value === null ? null : (string) $value);
    }
}
