<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\AiChatMessagePayload;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;

class CreateAIAssistChannelAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly Apps $app,
        protected readonly int $agentId,
    ) {
    }

    public function execute(): array
    {
        $slug = 'ai-assist-' . $this->lead->getId();

        $channelDto = ChannelDto::from([
            'apps' => $this->app,
            'companies' => $this->lead->company,
            'users' => $this->lead->user,
            'entity_id' => $this->lead->getId(),
            'entity_namespace' => Lead::class,
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
            'company' => $this->lead->company,
            'entity_id' => $this->lead->getId(),
            'entity_namespace' => Lead::class,
            'user' => $this->lead->user->toArray(),
            'canal_id' => 'ai-assist-' . $this->lead->getId(),
        ]);

        $session = new CreateSessionAction($sessionDto)->execute();

        return [
            'channel' => $channel,
            'session' => $session,
            'is_new_channel' => $channel->wasRecentlyCreated,
        ];
    }

    /**
     * Seed the freshly-created channel with an intro message so the user knows what this channel is for.
     * Company config wins over app config; if neither is set, nothing is posted.
     */
    private function postGreetingMessage(Channel $channel): void
    {
        $greeting = $this->lead->company->get(ConfigurationEnum::AI_ASSIST_GREETING_MSG->value)
            ?? $this->app->get(ConfigurationEnum::AI_ASSIST_GREETING_MSG->value);

        if (empty($greeting)) {
            return;
        }

        $user = $this->lead->company->getAiAgentUser() ?? $this->lead->user;

        $messageInput = new MessageInput(
            app: $this->app,
            company: $this->lead->company,
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
        $message->addEntity($this->lead);

        if ($this->lead->people !== null) {
            $message->addEntity($this->lead->people);
        }
    }
}
