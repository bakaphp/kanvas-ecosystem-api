<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Gmail;

use Kanvas\Connectors\Gmail\Actions\ReplyToEmailAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Approvals\Actions\ResolveApproverEmailAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Replies inside an existing email thread with an internal-only note — e.g. approval evidence,
 * a status update, or any other note that should stay attached to the original thread. The
 * recipient is always the approver configured on the record's vendor/customer — never the LLM's
 * choice, and never the thread's original external sender — so this can't be used to leak
 * internal notes out.
 */
#[AgentTool(name: 'Reply To Email', category: 'productivity')]
class ReplyToEmailTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'reply_to_email',
            description: 'Replies inside an existing email thread with an internal note (e.g. "Approved by X '
                . 'on Y"), as an audit trail. Always sent only to the approver configured on target_id\'s '
                . 'vendor/customer — never to the thread\'s original external sender. Commonly used right after '
                . 'approve_pending_item succeeds, using the message_id of the original email.',
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
                description: 'The Gmail message_id of the original email (from list_emails/read_email_details).',
                required: true,
            ),
            new ToolProperty(
                name: 'note',
                type: PropertyType::STRING,
                description: 'The note text, e.g. "Approved by Jane Doe on 2026-08-19 — Bill #1072."',
                required: true,
            ),
            new ToolProperty(
                name: 'target_type',
                type: PropertyType::STRING,
                description: 'The kind of record this reply is about, e.g. "bill" or "invoice" — used to look up '
                    . 'its vendor/customer approver.',
                required: true,
            ),
            new ToolProperty(
                name: 'target_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas id of that record — the bill_id from create_ap_bill, or the invoice_id '
                    . 'from create_ar_invoice.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $message_id, string $note, string $target_type, int $target_id): array
    {
        $approverEmail = new ResolveApproverEmailAction($target_type, $target_id)->execute();

        if ($approverEmail === null) {
            return [
                'replied' => false,
                'reason' => 'no_approver_configured',
                'message' => "No approver email is configured on this {$target_type}'s vendor/customer.",
            ];
        }

        try {
            $result = new ReplyToEmailAction($this->app, $message_id, [$approverEmail], $note)->execute();
        } catch (Throwable $e) {
            return [
                'replied' => false,
                'reason' => 'reply_failed',
                'message' => 'Could not reply to the email: ' . $e->getMessage(),
            ];
        }

        return [
            'replied' => true,
            ...$result,
        ];
    }
}
