<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DescribesPurchaseOrderLines;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Purchasing\Actions\CreatePurchaseOrderAction;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrder as PurchaseOrderData;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Creates a draft purchase order to a vendor; it never approves or sends it. */
#[AgentTool(name: 'Create Purchase Order', category: 'accounting')]
class CreatePurchaseOrderTool extends Tool
{
    use DescribesPurchaseOrderLines;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'create_purchase_order';

    protected ?string $description = 'Creates a draft purchase order to a vendor: what the company wants to buy, in '
        . 'what quantity and at what unit cost. It gets its own PO number and stays a draft: it is not approved and not '
        . 'sent to the vendor. Use it only when the person asks to order something from a vendor. It posts nothing to the '
        . 'ledger.';

    /**
     * @return array<int, ToolProperty|ArrayProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'vendor_organization_id',
                type: PropertyType::NUMBER,
                description: 'The Kanvas organization id of the vendor (find_vendor returns it).',
                required: true,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Three-letter currency code of the order, e.g. USD.',
                required: true,
            ),
            $this->purchaseOrderLinesProperty('One entry per item ordered. At least one.', required: true),
            new ToolProperty(
                name: 'expected_date',
                type: PropertyType::STRING,
                description: 'When the goods are expected, as YYYY-MM-DD.',
                required: false,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Anything the vendor or the team should know about this order.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        int $vendor_organization_id,
        string $currency,
        array $lines,
        ?string $expected_date = null,
        ?string $notes = null,
    ): array {
        $vendorExists = Organization::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->whereKey($vendor_organization_id)
            ->exists();

        if (! $vendorExists) {
            return [
                'created' => false,
                'reason' => 'vendor_not_found',
                'message' => "No vendor with organization id {$vendor_organization_id} for this company.",
            ];
        }

        try {
            $order = new CreatePurchaseOrderAction(
                PurchaseOrderData::fromMultiple(
                    $this->app,
                    $this->user,
                    $this->company,
                    [
                        'vendor_id' => $vendor_organization_id,
                        'currency' => $currency,
                        'lines' => $lines,
                        'expected_date' => $expected_date,
                        'notes' => $notes,
                    ]
                )
            )->execute();
        } catch (Throwable $e) {
            return [
                'created' => false,
                'reason' => 'purchase_order_failed',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'created' => true,
            'order_number' => $order->order_number,
            'document_status' => $order->document_status->value,
            'order_total' => (float) $order->order_total,
            'currency' => $order->currency,
            'line_count' => $order->lines->count(),
            'next' => 'The purchase order is a draft: it still has to be approved and sent to the vendor.',
        ];
    }
}
