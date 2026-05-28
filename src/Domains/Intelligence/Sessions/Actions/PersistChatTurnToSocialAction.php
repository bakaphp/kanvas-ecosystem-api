<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Actions;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Mirror one interactive agent⇄user chat turn into the Social layer: the user prompt and the
 * agent reply become two linked `Message` rows on the session's `Channel`, threaded via
 * `parent_id`/`response_message_id` and tagged with the originating `Session.uuid` so the
 * Social conversation stays tied to the Intelligence session.
 *
 * Workflows are intentionally NOT fired here — connectors persist inbound messages and fire
 * AFTER_ADDING_MESSAGE_TO_CHANNEL to trigger an auto-reply; this turn is already the reply, so
 * firing would loop.
 */
class PersistChatTurnToSocialAction
{
    protected string $messageTypeVerb = 'ai-chat';

    /**
     * @param list<string> $images
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
    ) {
    }

    public function execute(): Message
    {
        $entity = $this->resolveEntity();
        $channel = $this->resolveChannel($entity);
        $type = MessageTypeService::getOrCreate($this->app, $this->messageTypeVerb);
        $aiUser = $this->company->getAiAgentUser() ?? $this->user;

        $incoming = $this->createMessage(
            $type,
            $this->user,
            $this->userMessage,
            fromIa: false,
            extra: $this->images === [] ? [] : ['images' => $this->images],
        );

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
        $slug = SessionChannelService::createChannelSlug('ai-assist', (int) $this->agent->getId() . '-' . $entityId);

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
     * @param array<string, mixed> $extra
     */
    protected function createMessage(
        MessageType $type,
        Users $author,
        string $content,
        bool $fromIa,
        ?Message $parent = null,
        array $extra = [],
    ): Message {
        $input = new MessageInput(
            app: $this->app,
            company: $this->company,
            user: $author,
            type: $type,
            message: [
                'content' => $content,
                'from_me' => $fromIa,
                'from_ia' => $fromIa,
                'session_id' => $this->session->uuid,
                'agent_id' => $this->agent->getId(),
                ...$extra,
            ],
            parent_id: $parent?->getId(),
            is_public: 1,
        );

        $action = new CreateMessageAction($input);
        $action->runWorkflow = false;

        return $action->execute();
    }
}
