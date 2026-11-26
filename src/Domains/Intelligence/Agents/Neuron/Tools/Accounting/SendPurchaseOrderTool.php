<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\FindsPurchaseOrderByNumber;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\SendsEmailToPeople;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Ledger\Exceptions\InvalidDocumentTransitionException;
use Kanvas\Scribe\Purchasing\Actions\SendPurchaseOrderAction;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Send Purchase Order', category: 'accounting')]
class SendPurchaseOrderTool extends Tool
{
    use FindsPurchaseOrderByNumber;
    use HasKanvasContext;
    use SendsEmailToPeople;
    use TrackByInputs;

    protected string $name = 'send_purchase_order';

    protected ?string $description = 'Emails an approved purchase order to the vendor as a PDF and marks it sent. It is '
        . 'refused unless the order is approved (or already sent, to send it again). You cannot send it to an '
        . 'arbitrary address: every recipient must be the email on file of a person linked to the order\'s vendor '
        . '(list_organization_people shows them), and one address that is not refuses the whole send. A contact '
        . 'who is not on file is added to the vendor first with create_person and link_person_to_organization. It '
        . 'really sends an email, so use it only when the person asks to send the order.';

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
                description: 'The email addresses to send it to, each one on file for a person linked to the vendor.',
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

        ['accepted' => $recipients, 'rejected' => $refused] = $this->resolveCcRecipients(
            $order->vendor?->peoples()->pluck('peoples.id')->all() ?? [],
            implode(',', $to),
            ''
        );

        if ($refused !== []) {
            return [
                'sent' => false,
                'order_number' => $order->order_number,
                'reason' => 'recipient_not_allowed',
                'refused' => $refused,
                'message' => 'Nothing was sent. These addresses are not on file for a person linked to the vendor '
                    . '(or that person asked not to be contacted): ' . implode(', ', $refused) . '. Tell the person '
                    . 'plainly. To send it there, add the contact to the vendor first with create_person and '
                    . 'link_person_to_organization. Do not send the order any other way.',
            ];
        }

        try {
            $sent = new SendPurchaseOrderAction(
                order: $order,
                user: $this->user,
                recipients: $recipients,
                message: $message,
            )->execute();
        } catch (Throwable $e) {
            report_unless($e instanceof ValidationException || $e instanceof InvalidDocumentTransitionException, $e);

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
