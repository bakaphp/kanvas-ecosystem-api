<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Social;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\AttachesFileToEntity;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesMessageForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Attaches media or a document to a message — the post/activity surface, as opposed to the record
 * surfaces covered by upload_file_to_lead / _product / _variant.
 */
#[AgentTool(name: 'Upload File To Message', category: 'social')]
class UploadFileToMessageTool extends Tool implements HasRunKey
{
    use AttachesFileToEntity;
    use HasKanvasContext;
    use ResolvesMessageForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'upload_file_to_message',
            description: 'Attach a file or media to an existing message / activity post, so it shows up with the '
                . 'message instead of only as text. Normally you pass `content` — the full text of the document '
                . 'you wrote — plus a `file_name` ending in .md, .txt, .csv or .json. Pass `file_url` instead when '
                . 'the file or image already exists at a public URL. Use create_message to create the message '
                . 'first and take its message_id from there.',
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
                type: PropertyType::INTEGER,
                description: 'The ID of the message to attach the file to (from create_message).',
                required: true,
            ),
            new ToolProperty(
                name: 'file_name',
                type: PropertyType::STRING,
                description: 'File name to store it under, including extension — e.g. "handover-notes.md". '
                    . 'Defaults to the URL\'s own file name, or agent-document.md for inline content.',
                required: false,
            ),
            new ToolProperty(
                name: 'content',
                type: PropertyType::STRING,
                description: 'The full text of the document to store (markdown, plain text, CSV or JSON). Use this '
                    . 'when you are the one writing the document. Mutually exclusive with file_url.',
                required: false,
            ),
            new ToolProperty(
                name: 'file_url',
                type: PropertyType::STRING,
                description: 'A public URL to download the file or image from, when it already exists somewhere. '
                    . 'Mutually exclusive with content.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $message_id,
        ?string $file_name = null,
        ?string $content = null,
        ?string $file_url = null,
    ): array {
        $result = $this->resolveMessageOrError($message_id);
        if (is_array($result)) {
            return $result;
        }
        $message = $result;

        return [
            'message_id' => (int) $message->getId(),
            ...$this->attachFileToEntity(
                entity: $message,
                entityLabel: 'message',
                fileUrl: $file_url,
                content: $content,
                fileName: $file_name,
            ),
        ];
    }
}
