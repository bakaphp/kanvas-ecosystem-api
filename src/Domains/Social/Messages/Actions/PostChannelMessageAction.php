<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;

/**
 * Single primitive for posting a Message onto a Social Channel. Always attaches via
 * `$channel->addMessage()` — never `channel_slug` on MessageInput, which forces CreateChannelAction
 * and can trip on a null company (see PostPlanActivityMessageAction history).
 *
 * `attachToChannel` exists for callers that must fire the message's own CREATED workflow *between*
 * the entity attach and the channel attach (AbstractAgentChannelResponderAction — rules must see the
 * entity link, and the channel attach must stay the true last step). Set it false, call
 * `Channel::addMessage()` yourself once execute() returns.
 */
class PostChannelMessageAction
{
    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<string, mixed> $extraPayload
     * @param array<int, string> $tags
     */
    public function __construct(
        private readonly Channel $channel,
        private readonly Users $author,
        private readonly string $verb,
        private readonly string $content,
        private readonly array $extraPayload = [],
        private readonly array $files = [],
        private readonly ?int $parentId = null,
        private readonly bool $runWorkflow = false,
        private readonly ?Model $entity = null,
        private readonly array $tags = [],
        private readonly bool $attachToChannel = true,
        private readonly ?string $messageTypeName = null,
        private readonly ?string $template = null,
        private readonly mixed $templatesPlura = null,
        private readonly int $languagesId = 0,
    ) {
    }

    public function execute(): Message
    {
        $app = $this->channel->app;
        $company = $this->channel->company;

        $messageType = MessageTypeService::getOrCreate(
            app: $app,
            verb: $this->verb,
            name: $this->messageTypeName,
            template: $this->template,
            templatesPlura: $this->templatesPlura,
            languagesId: $this->languagesId,
        );

        $createMessage = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $this->author,
                type: $messageType,
                message: array_merge(['content' => $this->content], $this->extraPayload),
                parent_id: $this->parentId,
                tags: $this->tags,
                files: $this->files,
            ),
        );
        $createMessage->runWorkflow = $this->runWorkflow;

        $message = $createMessage->execute();

        if ($this->attachToChannel) {
            $this->channel->addMessage($message, $this->author);
        }

        if ($this->entity !== null) {
            $message->addEntity($this->entity);
        }

        return $message;
    }
}
