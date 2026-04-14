<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;

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
            'name' => ChannelNameEnum::AI_ASSIST->value . ' ' . $this->lead->getId(),
            'slug' => $slug,
        ]);

        $channel = new CreateChannelAction($channelDto)->execute();

        $channel->set(ConfigurationEnum::AGENT_CHANNEL_TYPE->value, 'AI_ASSIST');

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
}
