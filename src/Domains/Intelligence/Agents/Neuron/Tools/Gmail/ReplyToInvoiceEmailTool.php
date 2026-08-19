<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Gmail;

use Kanvas\Connectors\Gmail\Actions\ReplyToInvoiceEmailAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Approvals\Enums\ApprovalConfigurationEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Replies inside an invoice email's thread with an internal approval note, for audit purposes.
 * The recipient is always the configured approver's own email — never the LLM's choice, and never
 * the invoice's original external sender — so this can't be used to leak internal notes outward.
 */
#[AgentTool(name: 'Reply To Invoice Email', category: 'productivity')]
class ReplyToInvoiceEmailTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'reply_to_invoice_email',
            description: 'Replies inside an invoice email\'s thread with an internal approval note (e.g. '
                . '"Approved by X on Y"), as an audit trail. Always sent only to the configured internal '
                . 'approver — never to the invoice\'s original external sender. Call this right after '
                . 'approve_pending_item succeeds, using the message_id of the ORIGINAL invoice email.',
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
                name: 'message_id',
                type: PropertyType::STRING,
                description: 'The Gmail message_id of the original invoice email (from list_emails/read_email_details).',
                required: true,
            ),
            new ToolProperty(
                name: 'note',
                type: PropertyType::STRING,
                description: 'The approval evidence text, e.g. "Approved by Jane Doe on 2026-08-19 — Bill #1072."',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $message_id, string $note): array
    {
        $approverEmail = (string) ($this->app->get(ApprovalConfigurationEnum::APPROVER_EMAIL->value) ?? '');

        if ($approverEmail === '') {
            return [
                'replied' => false,
                'reason' => 'no_approver_configured',
                'message' => 'No approver email is configured for this app.',
            ];
        }

        try {
            $result = new ReplyToInvoiceEmailAction($this->app, $message_id, [$approverEmail], $note)->execute();
        } catch (Throwable $e) {
            return [
                'replied' => false,
                'reason' => 'reply_failed',
                'message' => 'Could not reply to the invoice email: ' . $e->getMessage(),
            ];
        }

        return [
            'replied' => true,
            ...$result,
        ];
    }
}
