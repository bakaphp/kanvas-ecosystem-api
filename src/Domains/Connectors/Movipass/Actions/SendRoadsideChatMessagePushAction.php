<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Notifications\RoadsideChatMessageNotification;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;

class SendRoadsideChatMessagePushAction
{
    private const UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    public const ROADSIDE_CHAT_VERB = 'assistance-chat';

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
     * The message verb is the only reliable roadside-chat signal the client sends — the DM
     * channel it posts to (dm-{userId}-{userId}) carries no order reference at all.
     */
    public function isRoadsideChatMessage(): bool
    {
        return $this->message->messageType?->verb === self::ROADSIDE_CHAT_VERB;
    }

    /**
     * Push the new chat message to the counterparty (the roadside order's other participant).
     * Self-guards on the 'assistance-chat' verb and a resolvable roadside order, so it is safe
     * to bind on a broad Channel/updated workflow rule.
     *
     * @return int number of recipients notified
     */
    public function execute(): int
    {
        if (! $this->isRoadsideChatMessage()) {
            return 0;
        }

        $order = $this->getRoadsideOrder();

        if ($order === null) {
            return 0;
        }

        $senderId = (int) $this->message->users_id;
        $recipients = $this->resolveRecipients($order, $senderId);

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

    /**
     * Prefer the channel's own members, but fall back to the roadside order's participants.
     * The real client posts to a dm-{a}-{b} channel that only ever holds the sender in its users
     * pivot, so when the customer writes there is no other member — only the order knows the mechanic.
     *
     * @return Collection<int, Users>
     */
    private function resolveRecipients(Order $order, int $senderId): Collection
    {
        $members = $this->channel->users()
            ->wherePivot('users_id', '!=', $senderId)
            ->wherePivot('is_deleted', 0)
            ->get();

        if ($members->isNotEmpty()) {
            return $members;
        }

        return $this->resolveOrderParticipants($order, $senderId);
    }

    /**
     * @return Collection<int, Users>
     */
    private function resolveOrderParticipants(Order $order, int $senderId): Collection
    {
        $assistanceCase = $order->metadata['assistance_case'] ?? ($order->metadata['data']['assistance_case'] ?? []);

        $participantIds = array_values(array_unique(array_filter(
            [
                (int) $order->users_id,
                (int) ($assistanceCase['mechanic']['user_id'] ?? 0),
            ],
            fn (int $id): bool => $id > 0 && $id !== $senderId,
        )));

        if ($participantIds === []) {
            return new Collection();
        }

        return Users::whereIn('id', $participantIds)->get();
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
            $order = $this->findOrderByUuid($channel, $matches[0]);
            if ($order !== null) {
                return $order;
            }
        }

        // Real client channels are plain DMs (dm-{userId}-{userId}) with no order link, so
        // fall back to the case whose customer and assigned mechanic are both DM members.
        return $this->resolveOrderFromChannelMembers($channel);
    }

    /**
     * The real client posts to a dm-{a}-{b} channel that only ever has the sender in its users
     * pivot (CreateChannelAction attaches the creator alone). The two parties are still recoverable
     * from the channel owner, the User entity_id, and the two ids embedded in the dm-{a}-{b} slug.
     *
     * @return array<int, int>
     */
    private function channelParticipantIds(Channel $channel): array
    {
        $ids = $channel->users->map(fn ($user): int => (int) $user->getId())->all();

        $ids[] = (int) $channel->users_id;

        if (! empty($channel->entity_id)
            && ! empty($channel->entity_namespace)
            && SystemModules::convertLegacySystemModules((string) $channel->entity_namespace) === Users::class
        ) {
            $ids[] = (int) $channel->entity_id;
        }

        if (preg_match('/^dm-/i', (string) $channel->slug) === 1
            && preg_match_all('/\d+/', (string) $channel->slug, $matches) > 0
        ) {
            foreach ($matches[0] as $segment) {
                $ids[] = (int) $segment;
            }
        }

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }

    private function resolveOrderFromChannelMembers(Channel $channel): ?Order
    {
        $memberIds = $this->channelParticipantIds($channel);

        if (count($memberIds) < 2) {
            return null;
        }

        $orders = Order::query()
            ->where('apps_id', $channel->apps_id)
            ->whereIn('users_id', $memberIds)
            ->whereHas('orderType', fn ($query) => $query->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value))
            ->orderByDesc('id')
            ->get();

        foreach ($orders as $order) {
            $mechanicId = (int) ($order->metadata['assistance_case']['mechanic']['user_id'] ?? 0);
            if ($mechanicId > 0 && in_array($mechanicId, $memberIds, true)) {
                return $order;
            }
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
