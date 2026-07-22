<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;

/**
 * The single path for posting a message onto a project's default channel — used for both inbound
 * ingest (transcripts/email/mentions) and outbound agent replies. Ensures the default channel exists
 * first (best-effort bind), stores the message under a typed verb, and never fires the message
 * workflow (the project's own execution loop drives what happens next).
 */
class PostProjectMessageAction
{
    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<string, mixed> $extraPayload
     */
    public function __construct(
        private readonly Project $project,
        private readonly string $verb,
        private readonly string $content,
        private readonly ?Users $author = null,
        private readonly array $files = [],
        private readonly array $extraPayload = [],
    ) {
    }

    public function execute(): Message
    {
        $author = $this->author ?? $this->project->user;
        if ($author === null) {
            throw new ValidationException('Project has no author to attribute the message to.');
        }

        if ($this->project->default_channel_id === null) {
            new BindProjectChannelAction($this->project)->execute();
            $this->project->refresh();
        }

        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                $this->project->apps_id,
                0,
                $this->verb,
                $this->verb,
            ),
        )->execute();

        $createMessage = new CreateMessageAction(
            new MessageInput(
                app: $this->project->app,
                company: $this->project->company,
                user: $author,
                type: $messageType,
                message: array_merge(['content' => $this->content], $this->extraPayload),
                channel_slug: $this->project->defaultChannel?->slug ?? $this->project->uuid,
                files: $this->files,
            ),
        );
        $createMessage->runWorkflow = false;

        return $createMessage->execute();
    }
}
