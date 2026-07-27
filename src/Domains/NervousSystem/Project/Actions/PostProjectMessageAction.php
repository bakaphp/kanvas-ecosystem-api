<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

/**
 * The single path for posting a message onto a project channel — used for both inbound ingest
 * (transcripts/email/mentions) and outbound agent replies. Posts to the project's default channel
 * unless an explicit $channel is given (e.g. reply on the plan thread the mention came from), stores
 * the message under a typed verb, and never fires the message workflow (the project's own execution
 * loop drives what happens next).
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
        private readonly bool $fromIa = false,
        private readonly ?int $parentMessageId = null,
        private readonly ?Channel $channel = null,
    ) {
    }

    public function execute(): Message
    {
        $author = $this->author ?? $this->project->user;
        if ($author === null) {
            throw new ValidationException('Project has no author to attribute the message to.');
        }

        $channel = $this->channel ?? $this->resolveDefaultChannel();
        if ($channel === null) {
            throw new ValidationException('Project has no channel to post the message to.');
        }

        return new PostChannelMessageAction(
            channel: $channel,
            author: $author,
            verb: $this->verb,
            content: $this->content,
            extraPayload: array_merge(['from_ia' => $this->fromIa], $this->extraPayload),
            files: $this->files,
            parentId: $this->parentMessageId,
            runWorkflow: false,
            messageTypeName: $this->verb,
        )->execute();
    }

    private function resolveDefaultChannel(): ?Channel
    {
        if ($this->project->default_channel_id === null) {
            new BindProjectChannelAction($this->project)->execute();
            $this->project->refresh();
        }

        return $this->project->defaultChannel;
    }
}
