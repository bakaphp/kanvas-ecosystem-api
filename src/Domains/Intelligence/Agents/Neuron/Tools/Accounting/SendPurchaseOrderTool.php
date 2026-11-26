<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\FindsPurchaseOrderByNumber;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Purchasing\Actions\SendPurchaseOrderAction;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Emails an approved purchase order to the vendor as a PDF. */
#[AgentTool(name: 'Send Purchase Order', category: 'accounting')]
class SendPurchaseOrderTool extends Tool
{
    use FindsPurchaseOrderByNumber;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'send_purchase_order';

    protected ?string $description = 'Emails an approved purchase order to the vendor as a PDF and marks it sent. It is '
        . 'refused unless the order is approved (or already sent, to send it again). It really sends an email, so use it '
        . 'only when the person asks to send the order and has given the vendor\'s email address: never guess one.';

    /**
     * @return array<int, ToolProperty|ArrayProperty>
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
            new ArrayProperty(
                name: 'to',
                description: 'The email addresses of the vendor contacts to send it to.',
                required: true,
                items: new ToolProperty(
                    name: 'email',
                    type: PropertyType::STRING,
                    description: 'An email address.',
                    required: true,
                ),
            ),
            new ToolProperty(
                name: 'message',
                type: PropertyType::STRING,
                description: 'An optional note for the vendor, added to the email.',
                required: false,
            ),
        ];
    }

    /**
     * @param list<string> $to
     *
     * @return array<string, mixed>
     */
    public function __invoke(string $order_number, array $to, ?string $message = null): array
    {
        $order = $this->purchaseOrderByNumber($order_number);

        if ($order === null) {
            return ['sent' => false] + $this->purchaseOrderNotFound($order_number);
        }

        try {
            $sent = new SendPurchaseOrderAction(
                order: $order,
                user: $this->user,
                recipients: $to,
                message: $message,
            )->execute();
        } catch (Throwable $e) {
            return [
                'sent' => false,
                'order_number' => $order->order_number,
                'reason' => 'send_failed',
                'message' => $e->getMessage(),
            ];
        }

        return [
            'sent' => true,
            'order_number' => $sent->order_number,
            'document_status' => $sent->document_status->value,
            'sent_to' => $sent->sent_to,
            'sent_at' => $sent->sent_at?->toIso8601String(),
        ];
    }
}
