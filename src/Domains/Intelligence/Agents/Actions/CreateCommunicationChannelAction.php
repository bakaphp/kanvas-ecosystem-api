<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Kanvas\Intelligence\Agents\DataTransferObject\CommunicationChannel;
use Kanvas\Intelligence\Agents\Models\CommunicationChannel as CommunicationChannelModel;

class CreateCommunicationChannelAction
{
    public function __construct(
        public CommunicationChannel $communicationChannel
    ) {
    }

    public function execute(): CommunicationChannelModel
    {
        $channel = CommunicationChannelModel::create([
            'apps_id' => $this->communicationChannel->app->getId(),
            'name' => $this->communicationChannel->name,
            'description' => $this->communicationChannel->description,
            'handler' => $this->communicationChannel->handler,
            'config' => $this->communicationChannel->config,
            'is_active' => $this->communicationChannel->is_active,
            'is_published' => $this->communicationChannel->is_published,
        ]);

        return $channel;
    }
}
