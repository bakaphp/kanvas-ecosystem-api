<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Tools\Social;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Laravel\Contracts\KanvasToolInterface;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasFileUploadToolSchema;
use Kanvas\Intelligence\Agents\Laravel\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Traits\AttachesFileToEntity;
use Kanvas\Social\Messages\Models\Message;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Laravel-AI counterpart of the Neuron upload_file_to_message tool — same body via
 * AttachesFileToEntity, so both frameworks store and attach files identically.
 */
#[AgentTool(name: 'Upload File To Message', category: 'social')]
class UploadFileToMessageTool implements KanvasToolInterface
{
    use AttachesFileToEntity;
    use HasFileUploadToolSchema;
    use HasKanvasContext;

    #[Override]
    public function description(): Stringable|string
    {
        return 'Attach a file or media to an existing message / activity post, so it shows up with the message '
            . 'instead of only as text. Normally you pass content — the full text of the document you wrote — plus '
            . 'a file_name ending in .md, .txt, .csv or .json. Pass file_url instead when the file or image '
            . 'already exists at a public URL.';
    }

    #[Override]
    public function handle(Request $request): Stringable|string
    {
        $messageId = $request->integer('message_id');

        $message = Message::query()
            ->where('id', $messageId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->first();

        if (! $message instanceof Message) {
            return "Message {$messageId} does not exist in this company.";
        }

        return $this->uploadFromRequest($request, $message, 'message', ['message_id' => $messageId]);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema
                ->integer()
                ->description('The ID of the message to attach the file to.')
                ->required(),
            ...$this->fileUploadSchema($schema, 'handover-notes.md'),
        ];
    }
}
