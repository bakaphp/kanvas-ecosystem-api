<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;

class CreateAIAssistChannelAction
{
    public function __construct(
        protected readonly Lead|People $entity,
        protected readonly Apps $app,
        protected readonly int $agentId,
    ) {
    }

    /**
     * Returns null when AI Assist is off for the company (and app), so callers can `?->execute()`.
     * Agent precedence: explicit workflow param, then the company's configured agent, then the fallback.
     */
    public static function ifEnabled(
        Lead|People $entity,
        Apps $app,
        array $params,
        int $fallbackAgentId
    ): ?self {
        $enabled = (bool) ($entity->company->get(ConfigurationEnum::AI_ASSIST_ENABLED->value)
            ?? $app->get(ConfigurationEnum::AI_ASSIST_ENABLED->value)
            ?? false);

        if (! $enabled) {
            return null;
        }

        $agentId = $params['ai_assist_agent_id']
            ?? $entity->company->get(ConfigurationEnum::AI_ASSIST_AGENT_ID->value)
            ?? $fallbackAgentId;

        return new self($entity, $app, (int) $agentId);
    }

    public function execute(): array
    {
        $slug = $this->slug();

        $channelDto = ChannelDto::from([
            'apps' => $this->app,
            'companies' => $this->entity->company,
            'users' => $this->entity->user,
            'entity_id' => $this->entity->getId(),
            'entity_namespace' => $this->entity::class,
            'name' => ChannelNameEnum::AI_ASSIST->value,
            'slug' => $slug,
        ]);

        $channel = new CreateChannelAction($channelDto)->execute();

        $channel->set(ConfigurationEnum::AGENT_CHANNEL_TYPE->value, 'AI_ASSIST');

        if ($channel->wasRecentlyCreated) {
            $this->postGreetingMessage($channel);
        }

        $sessionDto = Session::from([
            'agent' => Agent::getById($this->agentId),
            'channel' => $channel,
            'app' => $this->app,
            'company' => $this->entity->company,
            'entity_id' => $this->entity->getId(),
            'entity_namespace' => $this->entity::class,
            'user' => $this->entity->user->toArray(),
            'canal_id' => $slug,
        ]);

        $session = new CreateSessionAction($sessionDto)->execute();

        return [
            'channel' => $channel,
            'session' => $session,
            'is_new_channel' => $channel->wasRecentlyCreated,
        ];
    }

    /**
     * Lead and People ids overlap, and the session uuid and the chat deep-link are both built from
     * this slug alone — so a People channel cannot reuse the lead's "ai-assist-{id}".
     */
    private function slug(): string
    {
        return $this->entity instanceof People
            ? 'ai-assist-people-' . $this->entity->getId()
            : 'ai-assist-' . $this->entity->getId();
    }

    /**
     * Seed the freshly-created channel with an intro message so the user knows what this channel is for.
     * Company config wins over app config; if neither is set, nothing is posted.
     */
    private function postGreetingMessage(Channel $channel): void
    {
        $greeting = $this->entity->company->get(ConfigurationEnum::AI_ASSIST_GREETING_MSG->value)
            ?? $this->app->get(ConfigurationEnum::AI_ASSIST_GREETING_MSG->value);

        if (empty($greeting)) {
            return;
        }

        $user = $this->entity->company->getAiAgentUser() ?? $this->entity->user;

        $messageInput = new MessageInput(
            app: $this->app,
            company: $this->entity->company,
            user: $user,
            type: MessageTypeService::getOrCreate($this->app, 'ai-assist'),
            message: AiChatMessagePayload::from([
                'content' => $greeting,
                'from_me' => true,
                'from_ia' => true,
                'agent_id' => $this->agentId,
                'raw_data' => $greeting,
            ])->toArray(),
            tags: ['ai-assist'],
        );

        $createMessage = new CreateMessageAction($messageInput);
        $createMessage->runWorkflow = false;
        $message = $createMessage->execute();

        $channel->addMessage($message);
        $message->addEntity($this->entity);

        if ($this->entity instanceof Lead && $this->entity->people !== null) {
            $message->addEntity($this->entity->people);
        }
    }
}
