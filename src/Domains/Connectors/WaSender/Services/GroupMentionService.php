<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Baka\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

/**
 * Was the agent actually addressed in this burst?
 *
 * Harder than it looks under lid addressing: `contextInfo.mentionedJid` carries `@lid` values while
 * the agent only ever stored its phone number, so the two never compare equal.
 *
 * Our own lid arrives two ways, and it needs both. A `fromMe` message discloses it in
 * `key.participant`, but that alone never bootstraps `mention` mode — the agent won't speak until
 * it sees a mention, and it can't see one until it has spoken. So the first time the lid is needed
 * and missing we resolve it from our own phone through `/api/lid-from-phone-number`. Either way it
 * is cached on the receiver, and a failed lookup backs off rather than retrying every burst.
 */
final readonly class GroupMentionService
{
    public const string OWN_LID_KEY = 'own_group_lid';

    private const int QUOTE_LOOKBACK = 50;

    private const int LID_LOOKUP_BACKOFF_SECONDS = 3600;

    public function __construct(
        private ReceiverWebhook $receiver,
        private Channel $channel,
        /** Injectable so a test can resolve the lid without reaching WhatsApp. */
        private ?ContactService $contacts = null,
    ) {
    }

    /**
     * @param Collection<int, Message> $messages
     */
    public function isAddressed(Collection $messages): bool
    {
        $identities = $this->ownIdentities();
        $agentMessageIds = null;

        foreach ($messages as $message) {
            if ($this->mentions($message, $identities) || $this->quotesUsByAuthor($message, $identities)) {
                return true;
            }

            $quotedId = $message->message['quoted_message_id'] ?? null;

            if (! is_string($quotedId) || $quotedId === '') {
                continue;
            }

            // Resolved once per burst rather than once per quoting message.
            $agentMessageIds ??= $this->recentAgentMessageIds();

            if (in_array($quotedId, $agentMessageIds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The session talking in its own group is the one moment WhatsApp tells us our own lid.
     */
    public function rememberOwnLid(InboundMessage $inbound): void
    {
        if (! $inbound->isFromMe || $inbound->senderLid === null) {
            return;
        }

        $this->storeOwnLid($inbound->senderLid);
    }

    private function storeOwnLid(string $lid): void
    {
        $configuration = $this->receiver->configuration;

        if (($configuration[self::OWN_LID_KEY] ?? null) === $lid) {
            return;
        }

        $configuration[self::OWN_LID_KEY] = $lid;
        $this->receiver->configuration = $configuration;
        $this->receiver->saveOrFail();
    }

    /**
     * Every bare form we might be mentioned as: our number, and the lid if we have learned it.
     *
     * @return list<string>
     */
    public function ownIdentities(): array
    {
        $identities = [];
        $phone = $this->ownNumber();
        $lid = $this->receiver->configuration[self::OWN_LID_KEY] ?? null;

        if (! is_string($lid) || $lid === '') {
            $lid = $this->resolveOwnLid($phone);
        }

        if (is_string($lid) && $lid !== '') {
            $identities[] = InboundMessage::toBareId($lid);
        }

        if ($phone !== null) {
            $identities[] = $phone;
        }

        return array_values(array_unique(array_filter($identities)));
    }

    /**
     * Learning the lid from a `fromMe` message alone never bootstraps: in `mention` mode the agent
     * won't speak until it detects a mention, and it can't detect one until it has spoken. So the
     * first time we need it we ask WhatsApp directly: `/api/lid-from-phone-number` maps our number.
     */
    private function resolveOwnLid(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        // A number WhatsApp has no lid for, or a connector that is down, must not mean an HTTP
        // round-trip on every burst.
        if (Cache::get($this->lidLookupBackoffKey()) !== null) {
            return null;
        }

        try {
            $contacts = $this->contacts
                ?? new ContactService($this->receiver->app, $this->receiver->company);
            $lid = $contacts->getLidFromPhoneNumber($phone);
        } catch (Throwable) {
            $lid = null;
        }

        if ($lid === null) {
            Cache::put($this->lidLookupBackoffKey(), true, self::LID_LOOKUP_BACKOFF_SECONDS);

            return null;
        }

        $this->storeOwnLid((string) InboundMessage::toBareId($lid));

        return $lid;
    }

    private function lidLookupBackoffKey(): string
    {
        return 'wasender:own-lid-lookup:' . (int) $this->receiver->getId();
    }

    /**
     * Our number as a JID carries it: the company's connection, else the number the group agent
     * linked while the session is not a connection yet.
     */
    private function ownNumber(): ?string
    {
        $number = WaSenderHandler::connectionFor($this->receiver->app, $this->receiver->company)?->account_key
            ?? $this->numberTheGroupAgentLinked();

        return Str::trimToNull(Str::digitsOnly((string) $number));
    }

    private function numberTheGroupAgentLinked(): ?string
    {
        $agentId = GroupConfigEnum::agentId($this->receiver);

        if ($agentId === null) {
            return null;
        }

        try {
            return WaSenderHandler::connectedNumber(Agent::getById($agentId, $this->receiver->app));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $identities
     */
    private function mentions(Message $message, array $identities): bool
    {
        if ($identities === []) {
            return false;
        }

        foreach ((array) ($message->message['mentioned_jids'] ?? []) as $mentioned) {
            if (! is_string($mentioned)) {
                continue;
            }

            if (in_array(InboundMessage::toBareId($mentioned), $identities, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The quoted author's JID rides on the quote itself, so this catches a reply to the agent even
     * when the quoted message was never filed on our side.
     *
     * @param list<string> $identities
     */
    private function quotesUsByAuthor(Message $message, array $identities): bool
    {
        $quotedParticipant = $message->message['quoted_participant'] ?? null;

        return is_string($quotedParticipant)
            && $quotedParticipant !== ''
            && in_array(InboundMessage::toBareId($quotedParticipant), $identities, true);
    }

    /**
     * @return list<string>
     */
    private function recentAgentMessageIds(): array
    {
        return $this->channel->messages()
            ->orderBy('messages.id', 'desc')
            ->limit(self::QUOTE_LOOKBACK)
            ->get()
            ->filter(fn (Message $candidate): bool => (bool) ($candidate->message['from_ia'] ?? false))
            ->map(fn (Message $candidate): mixed => $candidate->message['message_id'] ?? null)
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();
    }
}
