<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Actions;

use Baka\Support\Str;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;

class CreateChannel
{
    public function __construct(
        protected ChannelDto $channelDto
    ) {
    }

    public function execute(): Channel
    {
        $channel = new Channel();
        $channel->name = $this->channelDto->name;
        $channel->slug = Str::slug($this->channelDto->name);
        $channel->description = $this->channelDto->description;
        $channel->entity_id = $this->channelDto->entity_id;
        $channel->entity_namespace = $this->channelDto->entity_namespace;
        $channel->saveOrFail();

        $channel->users()->attach($this->channelDto->users->id, ['roles_id' => 1]);

        return $channel;
    }
}
