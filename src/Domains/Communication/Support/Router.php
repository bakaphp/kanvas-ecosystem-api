<?php

declare(strict_types=1);

namespace Kanvas\Communication\Support;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Actions\EnsureBuiltInConnectionAction;
use Kanvas\Communication\Connections\Models\ChannelDefault;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\DeliveryRoute;
use Kanvas\Communication\Messages\DataTransferObject\Recipient;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
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
    public function forChannels(
        Recipient $recipient,
        array|string $channels,
        ?Sender $sender = null,
        ?string $fromAccount = null
    ): array {
        if ($channels === 'all') {
            return $this->everyUsableChannel($recipient);
        }

        return array_map(
            fn (ChannelTypeEnum $channel): DeliveryRoute => new DeliveryRoute(
                $channel,
                $this->connectionFor($channel, $sender, $fromAccount),
                $recipient->addressOn($channel)
            ),
            array_map(ChannelTypeEnum::from(...), array_values(array_unique((array) $channels)))
        );
    }

    /**
     * The account the caller named, else the one the speaking agent owns, else the company's default
     * for the channel. A named account the company does not have is no connection at all: a message
     * meant to leave from one number never quietly leaves from another.
     */
    public function connectionFor(
        ChannelTypeEnum $channel,
        ?Sender $sender = null,
        ?string $fromAccount = null
    ): ?Connection {
        if ($fromAccount !== null && $fromAccount !== '') {
            return $this->carrying($channel, fn (Builder $query) => $query->where('account_key', $channel->normalizeAddress($fromAccount)));
        }

        if ($sender?->isAgent() === true) {
            $owned = $this->ownedBy($channel, (int) $sender->id);

            if ($owned !== null) {
                return $owned;
            }
        }

        return $this->defaultConnection($channel);
    }

    /**
     * The agent's own connection on a channel and nothing else: no default, so asking never gives a
     * company its built-in connection.
     */
    public function ownedBy(ChannelTypeEnum $channel, int $agentId): ?Connection
    {
        return $this->carrying($channel, fn (Builder $query) => $query->where('config->agent_id', $agentId));
    }

    private function carrying(ChannelTypeEnum $channel, callable $narrow): ?Connection
    {
        $transports = new TransportManager();

        return Connection::query()
            ->fromApp($this->app)
            ->fromCompany($this->company->getId())
            ->live()
            ->tap($narrow)
            ->orderBy('id')
            ->get()
            ->first(fn (Connection $connection): bool => $transports->supports($connection->provider, $channel));
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
                $this->filedHere($message) ? Connection::forFiledMessage($message) : null,
                $filed['thread']
            );
        }

        $conversation = new TransportManager()->conversationOf($message);

        if ($conversation === null) {
            return null;
        }

        return new DeliveryRoute($conversation->channel, $this->connectionOf($conversation), $conversation->thread);
    }

    /**
     * A message names a connection of the workspace it was filed in; one filed in another workspace
     * is not answered from here.
     */
    private function filedHere(Message $message): bool
    {
        return (int) $message->apps_id === $this->app->getId()
            && (int) $message->companies_id === $this->company->getId();
    }

    /**
     * A live connection first: once an account is reconnected the old row is history, and a reply
     * has to leave through the row that is connected now.
     */
    private function connectionOf(ConversationRef $conversation): ?Connection
    {
        return Connection::query()
            ->fromApp($this->app)
            ->fromCompany($this->company->getId())
            ->where('provider', $conversation->provider)
            ->where('account_key', $conversation->accountKey)
            ->orderByRaw('active_key IS NULL')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<DeliveryRoute>
     */
    private function everyUsableChannel(Recipient $recipient): array
    {
        $eligibility = new EligibilityResolver();
        $routes = [];

        $defaults = ChannelDefault::query()
            ->fromApp($this->app)
            ->fromCompany($this->company->getId())
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

    /**
     * The connection the company chose for the channel, else what carries it for a company that
     * connected nothing (its own SMTP, for email).
     */
    public function defaultConnection(ChannelTypeEnum $channel): ?Connection
    {
        $chosen = ChannelDefault::query()
            ->fromApp($this->app)
            ->fromCompany($this->company->getId())
            ->where('channel', $channel->value)
            ->first();

        if ($chosen !== null) {
            return $chosen->connection;
        }

        $builtIn = new ProviderRegistry()->builtInFor($channel);

        return $builtIn !== null
            ? new EnsureBuiltInConnectionAction($this->app, $this->company, $builtIn)->execute()
            : null;
    }
}
