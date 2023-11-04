<?php

declare(strict_types=1);

namespace Kanvas\Social\Distribution;

use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class DistributionMessage
{
    public static function sentToChannelFeed(Channel $channel, Message $message): Channel
    {
        $channel->messages()->attach($message->id, [
            'users_id' => $message->users_id,
        ]);
        $channel->last_message_id = $message->id;
        $channel->save();

        return $channel;
    }
}
