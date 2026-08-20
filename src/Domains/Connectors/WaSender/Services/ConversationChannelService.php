<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\WaSender\Enums\ConversationTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * Everything that maps a WhatsApp JID to its Kanvas Channel and Message identity. One home for the
 * slug shapes so the upsert path, the status/reaction/receipt handlers and the three message
 * actions all resolve the same rows.
 */
final readonly class ConversationChannelService
{
    public function __construct(
        private ReceiverWebhook $receiver,
    ) {
    }

    public static function messageSlug(string $messageId, string $jid): string
    {
        return 'wa-' . Str::slug($messageId . '-' . $jid);
    }

    public static function channelSlug(string $jid): string
    {
        return match (ConversationTypeEnum::fromJid($jid)) {
            ConversationTypeEnum::GROUP => SessionChannelService::createChannelSlug('whatsapp-group', $jid),
            ConversationTypeEnum::NEWSLETTER => 'wa-channel-' . Str::slug($jid),
            ConversationTypeEnum::DIRECT => SessionChannelService::createChannelSlug(
                'whatsapp',
                str_replace('@s.whatsapp.net', '', $jid)
            ),
        };
    }

    public static function isGroupJid(string $jid): bool
    {
        return ConversationTypeEnum::fromJid($jid) === ConversationTypeEnum::GROUP;
    }

    public static function isChannelJid(string $jid): bool
    {
        return ConversationTypeEnum::fromJid($jid) === ConversationTypeEnum::NEWSLETTER;
    }

    /**
     * A 1:1 thread — i.e. a JID that stands for a person, so it can resolve to a People record.
     */
    public static function isDirectJid(string $jid): bool
    {
        return ConversationTypeEnum::fromJid($jid) === ConversationTypeEnum::DIRECT;
    }

    public function findChannel(string $jid): ?Channel
    {
        return Channel::where('slug', self::channelSlug($jid))
            ->where('companies_id', $this->receiver->company->getId())
            ->where('apps_id', $this->receiver->app->getId())
            ->first();
    }

    /**
     * Matches on `slug`, which is where CreateMessageAction writes the deterministic WhatsApp id —
     * `uuid` is a random UuidTrait value. Every status/reaction/receipt handler used to query
     * `uuid` here and so silently matched nothing, and message.sent double-filed instead of
     * updating.
     */
    public function findMessageBySlug(string $messageId, string $jid): ?Message
    {
        return Message::where('slug', self::messageSlug($messageId, $jid))
            ->where('companies_id', $this->receiver->company->getId())
            ->where('apps_id', $this->receiver->app->getId())
            ->first();
    }

    /**
     * Mutual exclusion is a cache lock, not `SELECT ... FOR UPDATE`.
     *
     * `channels.slug` carries only non-unique composite indexes, so locking a row that does not
     * exist yet gap-locks the whole index range: two workers opening different conversations then
     * deadlock on the insert-intention lock. The old transaction also ran on the default
     * connection while Channel writes to `social`, so it never guarded these writes at all.
     */
    public function getOrCreateChannel(string $jid, ?string $name = null, ?Lead $lead = null): Channel
    {
        // Every message after the first in a conversation takes this path — no lock, no
        // transaction, one indexed read.
        $channel = $this->findChannel($jid);

        if ($channel === null) {
            $channel = Cache::lock('wasender:channel:' . self::channelSlug($jid), 10)
                ->block(5, fn (): Channel => $this->findChannel($jid) ?? $this->createChannel($jid, $name, $lead));
        } elseif ($name && $channel->name !== $name) {
            $channel->name = $name;
            $channel->save();
        }

        if ($lead && empty($channel->entity_namespace)) {
            $channel->entity_namespace = get_class($lead->people);
            $channel->entity_id = $lead->people->getId();
            $channel->update();
        }

        // Guarded by a read: set() is a Redis write plus three custom-field queries and a workflow
        // fire, and this runs on every inbound message for the life of the channel.
        if ($channel->id && $channel->get(ConfigurationEnum::AGENT_CHANNEL_TYPE->value) !== 'WhatsApp') {
            $channel->set(
                ConfigurationEnum::AGENT_CHANNEL_TYPE->value,
                'WhatsApp'
            );
        }

        return $channel;
    }

    private function createChannel(string $jid, ?string $name, ?Lead $lead): Channel
    {
        return DB::connection('social')->transaction(function () use ($jid, $name, $lead): Channel {
            $channel = new Channel();
            $channel->name = $name ?? self::extractContactName($jid);
            $channel->description = self::describe($jid);
            $channel->slug = self::channelSlug($jid);
            $channel->companies_id = $this->receiver->company->getId();
            $channel->apps_id = $this->receiver->app->getId();

            if ($lead) {
                $channel->entity_namespace = get_class($lead);
                $channel->entity_id = $lead->getId();
            }

            // Saved unconditionally: a lead-less channel (group, newsletter) used to be built and
            // thrown away on every delivery, so it never got an id and the config set on the way
            // out was silently skipped.
            $channel->save();

            $channel->addTags(
                [
                    'whatsapp',
                    'ai-agent',
                ],
                $lead?->app ?? $this->receiver->app,
                $lead?->user ?? $this->receiver->user,
                $lead?->company ?? $this->receiver->company
            );

            $channel->addCategory(
                'ai-agent',
                $this->receiver->app,
                $this->receiver->user,
                $this->receiver->company
            );

            return $channel;
        });
    }

    public static function extractContactName(string $jid): string
    {
        return match (ConversationTypeEnum::fromJid($jid)) {
            ConversationTypeEnum::GROUP => self::extractGroupName($jid),
            ConversationTypeEnum::NEWSLETTER => 'WhatsApp Channel: ' . str_replace('@newsletter', '', $jid),
            ConversationTypeEnum::DIRECT => 'WhatsApp Chat: ' . str_replace('@s.whatsapp.net', '', $jid),
        };
    }

    /**
     * A group JID is `<owner>-<created_at>@g.us` and carries no human-readable name — inbound
     * payloads never include the subject, so this is a placeholder until groups.upsert or
     * ListWhatsAppGroupsAction supplies the real one.
     */
    public static function extractGroupName(string $jid): string
    {
        $jid = str_replace('@g.us', '', $jid);

        $parts = explode('-', $jid);
        if (count($parts) >= 2) {
            return 'WhatsApp Group: ' . substr($parts[0], 0, 5) . '...' . substr($parts[1], 0, 5);
        }

        return 'WhatsApp Group: ' . $jid;
    }

    private static function describe(string $jid): string
    {
        return match (ConversationTypeEnum::fromJid($jid)) {
            ConversationTypeEnum::GROUP => 'WhatsApp Group: ' . $jid,
            ConversationTypeEnum::NEWSLETTER => 'WhatsApp Channel: ' . $jid,
            ConversationTypeEnum::DIRECT => 'WhatsApp Chat: ' . $jid,
        };
    }
}
