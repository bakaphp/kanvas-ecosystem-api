<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTenantRecordForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Looks up ONE purchase order by its number — full header + every line with quantities and GL
 * coding. This is the "pull up PO #X" lookup (vs list_open_purchase_orders which enumerates). Reads
 * synced data; if the PO isn't in Kanvas it reports that (it may not have been synced yet).
 */
#[AgentTool(name: 'Find Purchase Order', category: 'accounting')]
class FindPurchaseOrderTool extends Tool implements HasRunKey
{
    use FindsTenantRecordForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_purchase_order',
            description: 'Look up a single purchase order by its number and return the full detail: vendor, '
                . 'status, total, and every line (sku, ordered/open/received quantity, unit cost, GL account + '
                . 'subaccount). Use this when the user names a specific PO number. Returns found=false when the '
                . 'PO is not in the synced data.',
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

        return [
            'found' => true,
            'order_number' => $po->order_number,
            'order_type' => $po->order_type,
            'vendor_code' => $po->vendor_code,
            'status' => $po->status,
            'order_date' => $po->order_date?->toDateString(),
            'currency' => $po->currency,
            'order_total' => (float) $po->order_total,
            'lines' => $po->lines()->orderBy('line_number')->get()->map(fn ($l): array => [
                'line_number' => (int) $l->line_number,
                'sku' => $l->sku,
                'description' => $l->description,
                'order_qty' => (float) $l->order_qty,
                'open_qty' => (float) $l->open_qty,
                'received_qty' => (float) $l->received_qty,
                'unit_cost' => (float) $l->unit_cost,
                'ext_cost' => (float) $l->ext_cost,
                'gl_account' => $this->code(Account::class, $l->expense_account_id, 'account_number'),
                'subaccount' => $this->code(Subaccount::class, $l->subaccount_id, 'sub_code'),
            ])->all(),
        ];
    }

    /**
     * @param class-string $model
     */
    private function code(string $model, ?int $id, string $column): ?string
    {
        if ($id === null) {
            return null;
        }

        $value = $model::query()->where('id', $id)->value($column);

        return $value !== null ? (string) $value : null;
    }
}
