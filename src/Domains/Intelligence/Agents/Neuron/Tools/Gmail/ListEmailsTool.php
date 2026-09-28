<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Gmail;

use Kanvas\Connectors\Gmail\Actions\ListEmailsAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Searches the connected Gmail mailbox, e.g. for unread invoice emails with attachments. */
#[AgentTool(name: 'List Emails', category: 'productivity')]
class ListEmailsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_emails',
            description: 'Searches the connected Gmail mailbox using Gmail\'s own search syntax (e.g. '
                . '"subject:Invoice has:attachment is:unread", "from:vendor@x.com"). Returns each match\'s '
                . 'message id, thread id, and subject — use read_email_details with a message id to get the '
                . 'full body and attachment list.',
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
                name: 'query',
                type: PropertyType::STRING,
                description: 'Gmail search syntax, e.g. "subject:Invoice has:attachment is:unread". Always required.',
                required: true,
            ),
            new ToolProperty(
                name: 'max_results',
                type: PropertyType::INTEGER,
                description: 'Maximum number of emails to return. Defaults to 10.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $query, ?int $max_results = null): array
    {
        try {
            $emails = new ListEmailsAction($this->app, $query, $max_results ?? 10)->execute();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'list_failed',
                'message' => 'Could not search the mailbox: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'count' => count($emails),
            'emails' => $emails,
        ];
    }
}
