<?php

declare(strict_types=1);

namespace Kanvas\Communication\Support;

use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\DeliveryRoute;
use Kanvas\Communication\Messages\DataTransferObject\Recipient;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\Message;

/**
 * Decides which connection a message goes through. Fixed in one place so no caller can route: a new
 * send uses the company's default for the channel, a reply uses the conversation it answers, and a
 * connection that cannot carry the message is never swapped for one that can.
 */
final class Router
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company
    ) {
    }

    /**
     * @param list<string>|string $channels channel names, or 'all'
     *
     * @return list<DeliveryRoute>
     */
    public function forChannels(Recipient $recipient, array|string $channels): array
    {
        if ($channels === 'all') {
            return $this->everyUsableChannel($recipient);
        }

        return array_map(
            fn (ChannelTypeEnum $channel): DeliveryRoute => $this->routeFor($recipient, $channel),
            array_map(ChannelTypeEnum::from(...), array_values(array_unique((array) $channels)))
        );
    }

    /**
     * The connection is read from the message when this domain filed it, and from what the provider
     * left on it otherwise — a conversation that started before connections existed still answers
     * from the number it came in on.
     */
    public function forConversation(Message $message): ?DeliveryRoute
    {
        $filed = $message->message['communication'] ?? null;

        if (is_array($filed) && isset($filed['connection_id'], $filed['channel'], $filed['thread'])) {
            return new DeliveryRoute(
                ChannelTypeEnum::from($filed['channel']),
                Connection::query()->fromApp($this->app)->fromCompany($this->company)->find($filed['connection_id']),
                $filed['thread']
            );
        }

        $conversation = app(TransportManager::class)->conversationOf($message);

        if ($conversation === null) {
            return null;
        }

        return new DeliveryRoute($conversation->channel, $this->connectionOf($conversation), $conversation->thread);
    }

    /**
     * A live connection first: once an account is reconnected the old row is history, and a reply
     * has to leave through the row that is connected now.
     */
    private function connectionOf(ConversationRef $conversation): ?Connection
    {
        return Connection::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('provider', $conversation->provider)
            ->where('account_key', $conversation->accountKey)
            ->orderByRaw('active_key IS NULL')
            ->orderByDesc('id')
            ->first();
    }

    private function routeFor(Recipient $recipient, ChannelTypeEnum $channel): DeliveryRoute
    {
        return new DeliveryRoute($channel, $this->defaultConnection($channel), $recipient->addressOn($channel));
    }

    /**
     * @return list<DeliveryRoute>
     */
    private function everyUsableChannel(Recipient $recipient): array
    {
        $eligibility = app(EligibilityResolver::class);
        $routes = [];

        $defaults = ChannelDefault::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->with('connection.integrationCompany')
            ->get();

        foreach ($defaults as $default) {
            $channel = ChannelTypeEnum::from($default->channel);
            $address = $recipient->addressOn($channel);

            $isUsable = $default->connection !== null
                && $default->connection->unavailableReason() === null
                && $address !== null
                && $eligibility->reasonNotToContact($recipient, $channel, $address) === null;

            if ($isUsable) {
                $routes[] = new DeliveryRoute($channel, $default->connection, $address);
            }
        }

        return $routes;
    }

    public function defaultConnection(ChannelTypeEnum $channel): ?Connection
    {
        return ChannelDefault::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('channel', $channel->value)
            ->first()
            ?->connection;
    }
}
