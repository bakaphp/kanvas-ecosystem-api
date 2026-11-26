<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\FindsPurchaseOrderByNumber;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Purchasing\Actions\LinkBillToPurchaseOrderAction;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderMatchService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** The three-way match of a vendor bill with a purchase order and what was received; optionally links them. */
#[AgentTool(name: 'Match Bill To Purchase Order', category: 'accounting')]
class MatchBillToPurchaseOrderTool extends Tool
{
    use FindsPurchaseOrderByNumber;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'match_bill_to_purchase_order';

    protected ?string $description = 'Matches a vendor bill with a purchase order and what was received against it: the bill '
        . 'must be from the order\'s vendor and in its currency, every bill line must be on the order, none may bill more '
        . 'than was received, and unit prices must agree with the order (within the company\'s tolerance). It lists every '
        . 'discrepancy instead of deciding for the person. With link true and a clean match it also links the bill to the '
        . 'order; a bill that does not match is never linked.';

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
                description: 'The purchase order number, e.g. PO-000012.',
                required: true,
            ),
            new ToolProperty(
                name: 'bill_number',
                type: PropertyType::STRING,
                description: 'The vendor\'s bill number, as entered on the bill. It is looked up among this order\'s vendor\'s bills.',
                required: true,
            ),
            new ToolProperty(
                name: 'link',
                type: PropertyType::BOOLEAN,
                description: 'True to link the bill to the order when it matches. Default false: only report.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number, string $bill_number, bool $link = false): array
    {
        $order = $this->purchaseOrderByNumber($order_number);

        if ($order === null) {
            return $this->purchaseOrderNotFound($order_number);
        }

        $bill = Bill::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('vendor_organization_id', $order->vendor_organization_id)
            ->where('bill_number', trim($bill_number))
            ->first();

        if ($bill === null) {
            return [
                'found' => false,
                'reason' => 'bill_not_found',
                'message' => "No bill numbered {$bill_number} from the vendor of purchase order {$order->order_number}.",
            ];
        }

        $match = new PurchaseOrderMatchService()->match($order, $bill);
        $result = ['order_number' => $order->order_number, 'bill_number' => $bill->bill_number] + $match->toArray();

        if (! $link) {
            return $result + ['linked' => false];
        }

        try {
            new LinkBillToPurchaseOrderAction($bill, $order)->execute();
        } catch (Throwable $e) {
            return $result + ['linked' => false, 'message' => $e->getMessage()];
        }

        return $result + ['linked' => true];
    }
}
