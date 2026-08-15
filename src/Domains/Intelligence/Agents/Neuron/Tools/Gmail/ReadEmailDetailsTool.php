<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Gmail;

use Kanvas\Connectors\Gmail\Actions\ReadEmailDetailsAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Reads one email's sender, date, subject, body, and attachment list, given the message id from list_emails. */
#[AgentTool(name: 'Read Email Details', category: 'productivity')]
class ReadEmailDetailsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'read_email_details',
            description: 'Reads one email\'s From, Date, Subject, body, and its attachments (filename + '
                . 'attachment_id for each). Use download_attachment with an attachment_id from here to save one '
                . 'to Kanvas.',
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
                description: 'The message id from list_emails. Always required.',
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
            $details = new ReadEmailDetailsAction($this->app, $message_id)->execute();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'read_failed',
                'message' => 'Could not read the email: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            ...$details,
        ];
    }
}
