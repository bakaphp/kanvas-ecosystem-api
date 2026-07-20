<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Str;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Notifications\RoadsideChatMessageNotification;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Models\SystemModules;

class SendRoadsideChatMessagePushAction
{
    private const UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    private ?Order $roadsideOrder = null;
    private bool $roadsideOrderResolved = false;

    public function __construct(
        protected Channel $channel,
        protected Message $message,
    ) {
    }

    public function getRoadsideOrder(): ?Order
    {
        if (! $this->roadsideOrderResolved) {
            $this->roadsideOrder = $this->resolveRoadsideOrder($this->channel);
            $this->roadsideOrderResolved = true;
        }

        return $this->roadsideOrder;
    }

    /**
     * Push the new chat message to every channel member except the sender.
     * Self-guards: only fires for roadside-assistance order channels, so it is
     * safe to bind on a broad Channel/updated workflow rule.
     *
     * @return int number of recipients notified
     */
    public function execute(): int
    {
        $order = $this->getRoadsideOrder();

        if ($order === null) {
            return 0;
        }

        $senderId = (int) $this->message->users_id;

        $recipients = $this->channel->users()
            ->wherePivot('users_id', '!=', $senderId)
            ->wherePivot('is_deleted', 0)
            ->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        $notification = new RoadsideChatMessageNotification(
            $order,
            (string) $this->channel->slug,
            $this->resolveSenderName($this->message),
            $this->resolvePreview($this->message),
            (int) $this->message->getKey(),
        );

        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }

        return $recipients->count();
    }

    private function resolveRoadsideOrder(Channel $channel): ?Order
    {
        $order = $this->resolveOrderCandidate($channel);

        if ($order === null) {
            return null;
        }

        return $order->orderType?->name === OrderTypeEnum::ROADSIDE_ASSISTANCE->value ? $order : null;
    }

    private function resolveOrderCandidate(Channel $channel): ?Order
    {
        // Channel linked directly to the order.
        if (! empty($channel->entity_id)
            && ! empty($channel->entity_namespace)
            && SystemModules::convertLegacySystemModules((string) $channel->entity_namespace) === Order::class
        ) {
            $order = Order::find((int) $channel->entity_id);
            if ($order !== null) {
                return $order;
            }
        }

        // Order reference carried in the channel metadata.
        $metadata = $channel->metadata ?? [];
        if (! empty($metadata['order_id'])) {
            $order = Order::find((int) $metadata['order_id']);
            if ($order !== null) {
                return $order;
            }
        }
        if (! empty($metadata['order_uuid'])) {
            $order = $this->findOrderByUuid($channel, (string) $metadata['order_uuid']);
            if ($order !== null) {
                return $order;
            }
        }

        // Order uuid embedded in the channel slug (e.g. "roadside-{uuid}").
        if (preg_match(self::UUID_PATTERN, (string) $channel->slug, $matches) === 1) {
            return $this->findOrderByUuid($channel, $matches[0]);
        }

        return null;
    }

    private function findOrderByUuid(Channel $channel, string $uuid): ?Order
    {
        return Order::where('uuid', $uuid)
            ->where('apps_id', $channel->apps_id)
            ->first();
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
