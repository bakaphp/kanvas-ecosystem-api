<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Listeners;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Kanvas\Guild\Leads\Actions\DeliverConversationMessageAction;
use Kanvas\Social\Channels\Events\ChannelMessageAttachedEvent;

final class DeliverHumanReplyListener implements ShouldQueue
{
    use InteractsWithQueue;
    use KanvasJobsTrait;

    public string $queue = 'workflow';

    // A retry after the send would message the customer twice.
    public int $tries = 1;

    public function shouldQueue(ChannelMessageAttachedEvent $event): bool
    {
        // On the default and internal channels a person writes without talking to the customer.
        return $event->message->isFromHuman()
            && ! $event->channel->isDefaultChannel()
            && ! $event->channel->isInternalChannel();
    }

    public function handle(ChannelMessageAttachedEvent $event): void
    {
        $this->overwriteAppService($event->message->app);

        new DeliverConversationMessageAction(
            $event->channel,
            $event->message,
            writtenByPerson: true
        )->execute();
    }
}
