<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsPurchaseOrderByNumber;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Purchasing\Actions\SubmitPurchaseOrderForApprovalAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Submits a draft purchase order to its approvers; it never approves it. */
#[AgentTool(name: 'Submit Purchase Order For Approval', category: 'accounting')]
class SubmitPurchaseOrderForApprovalTool extends Tool
{
    use FindsPurchaseOrderByNumber;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'submit_purchase_order_for_approval';

    protected ?string $description = 'Submits a draft purchase order for approval: the vendor\'s approver is asked, and the '
        . 'order waits as pending approval. It never approves anything: the approver decides, and approve_pending_item '
        . 'records an approval only when the approver says so. A company with no approval policy for purchase orders '
        . 'cannot submit one. Use it when the person says the order is ready to be approved.';

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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number): array
    {
        $order = $this->purchaseOrderByNumber($order_number);

        if ($order === null) {
            return ['submitted' => false] + $this->purchaseOrderNotFound($order_number);
        }

        try {
            $submitted = new SubmitPurchaseOrderForApprovalAction($order, $this->user)->execute();
        } catch (Throwable $e) {
            return [
                'submitted' => false,
                'order_number' => $order->order_number,
                'reason' => 'submit_failed',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'submitted' => true,
            'order_number' => $submitted->order_number,
            'document_status' => $submitted->document_status->value,
            'approvers' => $submitted->pendingApproval()?->pendingApproverEmails() ?? [],
            'next' => 'The order is pending approval; it can be sent to the vendor once it is approved.',
        ];
    }
}
