<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\Actions\MarkLeadMessagesAsRespondedAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Workflows are NOT fired on these messages: connectors fire AFTER_ADDING_MESSAGE_TO_CHANNEL
 * to trigger an auto-reply, but this turn is already the reply, so firing would loop.
 */
class PersistChatTurnToSocialAction
{
    protected string $messageTypeVerb = 'ai-chat';

    /**
     * @param list<string> $images
     * @param list<Filesystem> $attachments Freshly uploaded files attached to the user prompt.
     */
    public function __construct(
        protected readonly Session $session,
        protected readonly Agent $agent,
        protected readonly Apps $app,
        protected readonly Companies $company,
        protected readonly Users $user,
        protected readonly string $userMessage,
        protected readonly string $assistantResponse,
        protected readonly array $images = [],
        protected readonly array $attachments = [],
    ) {
    }

    public function execute(): Message
    {
        $entity = $this->resolveEntity();
        $channel = $this->resolveChannel($entity);
        $type = MessageTypeService::getOrCreate($this->app, $this->messageTypeVerb);
        // Agent's own user → company AI user → human (last resort): message always has an author.
        $aiUser = $this->agent->user ?? $this->company->getAiAgentUser() ?? $this->user;

        $incoming = $this->createMessage(
            $type,
            $this->user,
            $this->userMessage,
            fromIa: false,
            images: $this->images,
        );

        // Use a unique tag per upload — AttachFilesystemAction replaces on tag collision, so
        // a shared "attachment" tag would let later uploads silently overwrite earlier ones.
        foreach ($this->attachments as $filesystem) {
            $tag = $filesystem->name !== '' ? $filesystem->name : 'attachment-' . (string) $filesystem->getId();
            $incoming->addFile($filesystem, $tag);
        }

        $reply = $this->createMessage(
            $type,
            $aiUser,
            $this->assistantResponse,
            fromIa: true,
            parent: $incoming,
        );

        $reply->response_message_id = $incoming->getId();
        $reply->saveOrFail();

        if ($entity instanceof Model) {
            $incoming->addEntity($entity);
            $reply->addEntity($entity);
        }

        $channel->addMessage($incoming, $this->user);
        $channel->addMessage($reply, $aiUser);

        if ($entity instanceof Lead) {
            new MarkLeadMessagesAsRespondedAction($entity, $reply)->execute();
        }

        return $reply;
    }

    protected function resolveEntity(): ?Model
    {
        try {
            return $this->session->entity();
        } catch (Throwable) {
            return null;
        }
    }

    protected function resolveChannel(?Model $entity): Channel
    {
        if ($this->session->channel instanceof Channel) {
            return $this->session->channel;
        }

        $entityId = (int) ($entity?->getId() ?? $this->user->getId());
        $slug = SessionChannelService::createChannelSlug(
            'ai-assist',
            (int) $this->agent->getId() . '-' . $entityId
        );

        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $this->app,
                companies: $this->company,
                users: $this->user,
                name: 'AI chat with ' . $this->agent->name,
                description: 'AI conversation with agent ' . $this->agent->name,
                entity_id: $entityId,
                entity_namespace: $entity !== null ? $entity::class : Users::class,
                slug: $slug,
            )
        )->execute();

        $this->session->channel_id = $channel->getId();
        $this->session->saveOrFail();

        return $channel;
    }

    /**
     * @param list<string> $images
     */
    protected function createMessage(
        MessageType $type,
        Users $author,
        string $content,
        bool $fromIa,
        ?Message $parent = null,
        array $images = [],
    ): Message {
        $input = new MessageInput(
            app: $this->app,
            company: $this->company,
            user: $author,
            type: $type,
            message: AiChatMessagePayload::from([
                'content' => $content,
                'from_me' => $fromIa,
                'from_ia' => $fromIa,
                'session_id' => $this->session->uuid,
                'agent_id' => (int) $this->agent->getId(),
                'images' => $images,
            ])->toArray(),
            parent_id: $parent?->getId(),
            is_public: 1,
        );

        $action = new CreateMessageAction($input);
        $action->runWorkflow = false;

        return $action->execute();
    }
}
