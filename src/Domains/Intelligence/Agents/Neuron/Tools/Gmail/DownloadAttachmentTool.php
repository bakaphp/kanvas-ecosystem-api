<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Gmail;

use Kanvas\Connectors\Gmail\Actions\DownloadAttachmentAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Downloads one email attachment (e.g. an invoice PDF) and stores it as a Kanvas Filesystem entry for the agent/PDF classifier to process. */
#[AgentTool(name: 'Download Attachment', category: 'productivity')]
class DownloadAttachmentTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'download_attachment',
            description: 'Downloads one attachment from an email (identified by message_id + attachment_id from '
                . 'read_email_details) and saves it as a Kanvas Filesystem entry, returning its filesystem_id and '
                . 'url — the same way any other uploaded document is referenced in Kanvas. Use for pulling an '
                . 'invoice PDF out of an email so it can be processed.',
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
            new ToolProperty(
                name: 'attachment_id',
                type: PropertyType::STRING,
                description: 'The attachment_id from read_email_details. Always required.',
                required: true,
            ),
            new ToolProperty(
                name: 'filename',
                type: PropertyType::STRING,
                description: 'The filename from read_email_details\'s attachment list, e.g. "invoice-4521.pdf". '
                    . 'Always required.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $message_id, string $attachment_id, string $filename): array
    {
        try {
            $result = new DownloadAttachmentAction(
                $this->app,
                $this->company,
                $this->user,
                $message_id,
                $attachment_id,
                $filename,
            )->execute();
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'download_failed',
                'message' => 'Could not download the attachment: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            ...$result,
        ];
    }
}
