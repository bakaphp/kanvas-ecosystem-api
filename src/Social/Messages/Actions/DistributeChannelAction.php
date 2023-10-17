<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Channels\Repositories\ChannelRepository;
use Kanvas\Social\Distribution\Jobs\SendToChannelJob;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

class DistributeChannelAction
{
    public function __construct(
        public array $channels,
        public Message $message,
        public Users $user
    ) {
    }

    public function execute()
    {
        $channelsDataBase = [];
        if($this->channels) {
            foreach($this->channels as $channel) {
                $channelsDataBase[] = ChannelRepository::getById((int)$channel, $this->user);
            }
        } else {
            $channelsDataBase = $user->channels;
        }
        SendToChannelJob::dispatch($channelsDataBase, $this->message)->onQueue('kanvas-social');
    }
}
