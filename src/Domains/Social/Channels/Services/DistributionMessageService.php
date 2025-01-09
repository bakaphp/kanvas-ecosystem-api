<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Services;

use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;

class DistributionMessageService
{
    public static function sentToChannelFeed(Channel $channel, Message $message): Channel
    {
        $channel->addMessage($message);

        return $channel;
    }
}
