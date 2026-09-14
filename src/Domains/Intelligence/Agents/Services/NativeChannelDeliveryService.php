<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Services;

use Baka\Support\Str;
use Kanvas\Connectors\Slack\Client as SlackClient;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\WaSender\Services\MessageService as WaSenderMessageService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;

/**
 * Push an agent's message OUT over the connector a channel came in on.
 *
 * A connector channel is a mirror, and the mirror is one-way: an inbound Slack message is copied into
 * a Kanvas channel, but writing to that channel adds a row and nothing else. Replies reach Slack only
 * because the responder calls the client explicitly — so any other path that wants to be seen in the
 * conversation (a scheduled reminder, a finished plan) has to make the same call.
 *
 * The destination is recovered from the session's `canal_id` rather than the channel slug, because the
 * slug is lowercased and Slack/WhatsApp ids are case-sensitive.
 */
class NativeChannelDeliveryService
{
    /**
     * @return bool whether a native push actually went out — false means the connector could not be
     *              resolved, so the caller should fall back to a notification
     */
    public static function deliver(
        ?Channel $channel,
        string $text,
        ?Agent $agent,
        ?string $canalId,
    ): bool {
        if ($channel === null) {
            return false;
        }

        $type = strtolower(self::channelType($channel));

        return match (true) {
            str_contains($type, 'slack') => self::pushSlack($text, $agent, $canalId),
            str_contains($type, 'whatsapp') => self::pushWhatsApp($channel, $text, $canalId),
            str_contains($type, 'sms') || str_contains($type, 'twilio') => self::pushSms($channel, $text),
            default => false,
        };
    }

    private static function channelType(Channel $channel): string
    {
        $type = (string) ($channel->get(ConfigurationEnum::AGENT_CHANNEL_TYPE->value) ?? '');

        return $type !== '' ? $type : (string) $channel->slug;
    }

    /**
     * Slack identifies the sender by the bot token, which lives on an AGENT — so an agent that was
     * never connected to Slack cannot speak, and the channel stays quiet rather than erroring.
     */
    private static function pushSlack(string $text, ?Agent $agent, ?string $canalId): bool
    {
        if ($agent === null) {
            return false;
        }

        [$slackChannelId, $threadTs] = self::slackTargetFromCanalId($canalId);

        if ($slackChannelId === '') {
            return false;
        }

        SlackClient::getInstanceByAgent($agent)
            ->postMessage($slackChannelId, $text, $threadTs !== '' ? $threadTs : null);

        return true;
    }

    /**
     * @return array{0: string, 1: string} [slackChannelId, threadTs] parsed from the session canal_id
     *                                     `slack:{team}:{channel}:{thread_ts}` — taken verbatim, since
     *                                     the ids are case-sensitive and the slug is lowercased
     */
    private static function slackTargetFromCanalId(?string $canalId): array
    {
        if ($canalId === null || ! str_starts_with($canalId, 'slack:')) {
            return ['', ''];
        }

        $parts = explode(':', $canalId);

        return [$parts[2] ?? '', $parts[3] ?? ''];
    }

    private static function pushWhatsApp(Channel $channel, string $text, ?string $canalId): bool
    {
        if ($canalId === null || ! str_contains($canalId, '@s.whatsapp.net')) {
            return false;
        }

        new WaSenderMessageService($channel->app, $channel->company)
            ->sendTextMessage(str_replace('@s.whatsapp.net', '', $canalId), $text);

        return true;
    }

    private static function pushSms(Channel $channel, string $text): bool
    {
        $company = $channel->company;

        $from = (string) (
            $company->get(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value)
            ?? $company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value)
            ?? ''
        );

        // The Twilio channel slug is `twilio-<phone>`, so the recipient number lives there — no Lead.
        $to = Str::toE164(str_replace('twilio-', '', (string) $channel->slug));

        if ($from === '' || $to === '') {
            return false;
        }

        TwilioClient::getInstanceByCompany($company)
            ->messages
            ->create($to, ['from' => $from, 'body' => $text]);

        return true;
    }
}
