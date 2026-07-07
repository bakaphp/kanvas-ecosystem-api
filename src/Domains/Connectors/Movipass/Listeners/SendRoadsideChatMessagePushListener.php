<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Listeners;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Notifications\RoadsideChatMessageNotification;
use Kanvas\Social\Channels\Events\ChannelMessageCreatedEvent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Models\SystemModules;

class SendRoadsideChatMessagePushListener implements ShouldQueue
{
    use InteractsWithQueue;
    use KanvasJobsTrait;

    public int $tries = 2;

    // The event is dispatched from inside CreateMessageAction's social DB transaction.
    // Wait for commit so the worker reads a persisted message/channel membership.
    public bool $afterCommit = true;

    public function handle(ChannelMessageCreatedEvent $event): void
    {
        $channel = $event->getChannel();

        if (! $this->isOrderChannel($channel)) {
            return;
        }

        $this->overwriteAppService($channel->app);

        $order = $this->resolveRoadsideOrder($channel);

        if ($order === null) {
            return;
        }

        $message = $event->getMessage();
        $senderId = (int) $message->users_id;

        $recipients = $channel->users()
            ->wherePivot('users_id', '!=', $senderId)
            ->wherePivot('is_deleted', 0)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $notification = new RoadsideChatMessageNotification(
            $order,
            (string) $channel->slug,
            $this->resolveSenderName($message),
            $this->resolvePreview($message),
            (int) $message->getKey(),
        );

        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }

    private function isOrderChannel(Channel $channel): bool
    {
        if (empty($channel->entity_id) || empty($channel->entity_namespace)) {
            return false;
        }

        return SystemModules::convertLegacySystemModules((string) $channel->entity_namespace) === Order::class;
    }

    private function resolveRoadsideOrder(Channel $channel): ?Order
    {
        $order = Order::find((int) $channel->entity_id);

        if ($order === null) {
            return null;
        }

        return $order->orderType?->name === OrderTypeEnum::ROADSIDE_ASSISTANCE->value ? $order : null;
    }

    private function resolveSenderName(Message $message): string
    {
        $name = trim((string) ($message->user?->displayname ?? ''));

        return $name !== '' ? $name : 'Roadside Assistance';
    }

    private function resolvePreview(Message $message): string
    {
        $content = $message->getMessage();

        foreach (['message', 'text', 'content', 'body'] as $key) {
            if (isset($content[$key]) && is_string($content[$key]) && trim($content[$key]) !== '') {
                return Str::limit(trim($content[$key]), 120);
            }
        }

        foreach ($content as $value) {
            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 120);
            }
        }

        return 'You have a new message';
    }
}
