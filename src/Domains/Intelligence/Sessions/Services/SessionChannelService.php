<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Sessions\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Models\Channel;

class SessionChannelService
{
    public static function createCanalId(string $channel, string $id): string
    {
        $normalizedId = Str::normalizePhoneNumber($id);

        return match ($channel) {
            'whatsapp' => $normalizedId . '@s.whatsapp.net',
            // Already a full group JID (`<owner>-<created_at>@g.us`). Not normalized: it is not a
            // phone number and normalizePhoneNumber() would strip it to digits.
            'whatsapp-group' => $id,
            'sms' => '+' . $normalizedId,
            'email' => $id,
            'respondio' => '+' . $normalizedId,
            'ai-assist' => 'ai-assist-' . $id,
            // `{team}:{channel}:{thread_ts}` — the thread is the conversation. Not normalized:
            // Slack ids are alphanumeric and normalizePhoneNumber() would strip them to digits.
            'slack' => 'slack:' . $id,
        };
    }

    public static function createChannelSlug(string $channel, string $id): string
    {
        $normalizedId = Str::normalizePhoneNumber($id);

        return match ($channel) {
            'whatsapp' => 'wa-chat-' . $normalizedId . '-at-swhatsappnet',
            // Kept byte-for-byte compatible with the slug the WaSender webhook has always written
            // for groups, so existing channels keep resolving.
            'whatsapp-group' => 'wa-group-' . Str::slug($id),
            'sms' => 'twilio-' . $normalizedId,
            'email' => 'email-' . Str::sanitizeEmail($id),
            'respondio' => 'respondio-' . $normalizedId,
            'ai-assist' => 'ai-assist-' . $id,
            // `{team}-{channel}` — one Kanvas channel per Slack conversation (a room, or a DM).
            'slack' => 'slack-' . strtolower($id),
        };
    }

    /**
     * Canonical Session.uuid for a channel-scoped agent conversation. Every runtime chat path
     * keys on this so OpenClaw/Hermes transcripts, the Intelligence Session, and the Social
     * channel share one id.
     */
    public static function buildChannelSessionUuid(?Channel $channel, AppInterface $app, CompanyInterface $company): string
    {
        $channelSlug = $channel?->slug ?? 'no-channel';

        return $channelSlug . '-' . (int) $app->getId() . '-' . (int) $company->getId();
    }

    /**
     * Build a deep-link to the Kanvas AI chat frontend for the given entity's active conversation.
     *
     * Used by connectors (SalesAssist, DealerSocket, Elead, etc.) that post notes back to
     * external CRMs — the link lets CRM users click through from the lead note into the full
     * chat thread on our side. Callers append `?openInSa=true` when the target is the
     * SalesAssist browser extension, or use the raw link for the Kanvas frontend chat.
     *
     * Resolution rules:
     *   - Base URL comes from the `kanvas_ai_assistant_base_url` app config.
     *   - `location` is the entity's branch UUID.
     *   - `channel` is the slug of the entity's first email/sms/whatsapp/ai-assist social channel
     *     when the entity is a Lead; otherwise the UUID of its most recent agent-assisted Session.
     *
     * Returns null when the base URL is not configured or no channel/session can be resolved —
     * in that case callers should skip rendering the link rather than emitting a broken URL.
     *
     * Output: `{base}/chats/{entityId}?app={appKey}&location={branchUuid}&channel={channelSlug}`
     */
    public static function generateChannelLink(Model $entity, AppInterface $app): ?string
    {
        $baseUrl = $app->get('kanvas_ai_assistant_base_url');

        if (empty($baseUrl)) {
            return null;
        }

        $locationId = $entity->branch->uuid;
        $channelSlug = Session::fromApp($app)
            ->where('entity_namespace', get_class($entity))
            ->where('entity_id', $entity->getId())
            ->whereNotNull('agents_id')
            ->first()?->uuid;

        if ($entity instanceof Lead) {
            /**
             * @todo make the channel dynamic based on the lead's preferred contact method
             */
            $channelSlug = $entity->socialChannels()
                ->where(function (Builder $query) {
                    $query->where('name', 'like', '%email%')
                        ->orWhere('name', 'like', '%sms%')
                        ->orWhere('name', 'like', '%whatsapp%')
                        ->orWhere('name', 'like', '%ai assist%');
                })
                ->first()?->slug;
        }

        if ($channelSlug === null) {
            return null;
        }

        return sprintf(
            '%s/chats/%s?app=%s&location=%s&channel=%s',
            $baseUrl,
            $entity->getId(),
            $app->key,
            $locationId,
            $channelSlug
        );
    }
}
