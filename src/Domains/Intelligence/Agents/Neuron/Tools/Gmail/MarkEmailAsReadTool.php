<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Gmail;

use Kanvas\Connectors\Gmail\Actions\MarkEmailAsReadAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Marks an email as read so it stops matching an is:unread search — call this once an invoice email has been fully processed and logged. */
#[AgentTool(name: 'Mark Email As Read', category: 'productivity')]
class MarkEmailAsReadTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'mark_email_as_read',
            description: 'Marks an email as read so a future "has:attachment is:unread" search won\'t find it '
                . 'again. Call this after an invoice email has been fully processed (logged to the sheet, and '
                . 'the bill/invoice created) — never before, so a failed or interrupted run can still be found '
                . 'and retried.',
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
                description: 'The message id from list_emails/read_email_details. Always required.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $message_id): array
    {
        try {
            $result = new MarkEmailAsReadAction($this->app, $message_id)->execute();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'mark_read_failed',
                'message' => 'Could not mark the email as read: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            ...$result,
        ];
    }
}
