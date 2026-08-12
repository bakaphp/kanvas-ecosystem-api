<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Slack\Client as SlackClient;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\WaSender\Services\MessageService as WaSenderMessageService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Delivers a scheduled reminder / agent-task reply INTO the conversation channel it was scheduled from.
 *
 * Two layers:
 *  1. Persist onto the channel feed (channel-agnostic, deterministic) so it shows in the Kanvas thread
 *     for every channel type. This always runs.
 *  2. Best-effort native push over the connector, with the destination recovered FROM THE CHANNEL itself
 *     (Slack `slack_channel`, WhatsApp `chat_jid`, SMS phone from the `twilio-<phone>` slug) — no Lead
 *     required, so it works for internal DMs too.
 *
 * `execute()` returns whether a native push actually went out. The caller uses that to decide whether to
 * fall back to a general notification (mail/sms/push to the recipient) — so a channel we can't push to
 * natively (email, internal, or a connector with no credentials) never leaves the user un-pinged.
 */
class DeliverScheduledMessageToChannelAction
{
    public function __construct(
        private readonly Channel $channel,
        private readonly string $text,
        private readonly Users $author,
        private readonly ?Agent $agent = null,
        private readonly string $verb = 'scheduled-reminder',
    ) {
    }

    /**
     * @return bool true if a native connector push was delivered (so no notification fallback is needed)
     */
    public function execute(): bool
    {
        new PostChannelMessageAction(
            channel: $this->channel,
            author: $this->author,
            verb: $this->verb,
            content: $this->text,
            extraPayload: ['from_ia' => true],
            runWorkflow: false,
        )->execute();

        try {
            return $this->pushNative();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function pushNative(): bool
    {
        $type = strtolower($this->channelType());

        return match (true) {
            str_contains($type, 'slack') => $this->pushSlack(),
            str_contains($type, 'whatsapp') => $this->pushWhatsApp(),
            str_contains($type, 'sms') || str_contains($type, 'twilio') => $this->pushSms(),
            default => false,
        };
    }

    private function channelType(): string
    {
        $type = (string) ($this->channel->get(ConfigurationEnum::AGENT_CHANNEL_TYPE->value) ?? '');

        return $type !== '' ? $type : (string) $this->channel->slug;
    }

    private function pushSlack(): bool
    {
        if ($this->agent === null) {
            return false;
        }

        $latest = $this->latestMessage();
        $slackChannelId = (string) ($latest?->message['slack_channel'] ?? '');
        if ($slackChannelId === '') {
            return false;
        }

        $threadTs = (string) ($latest?->message['slack_thread_ts'] ?? '');

        SlackClient::getInstanceByAgent($this->agent)
            ->postMessage($slackChannelId, $this->text, $threadTs !== '' ? $threadTs : null);

        return true;
    }

    private function pushWhatsApp(): bool
    {
        $latest = $this->latestMessage();
        $chatJid = (string) ($latest?->message['chat_jid'] ?? '');
        if ($chatJid === '') {
            return false;
        }

        $to = str_replace('@s.whatsapp.net', '', $chatJid);

        new WaSenderMessageService($this->channel->app, $this->channel->company)
            ->sendTextMessage($to, $this->text);

        return true;
    }

    private function pushSms(): bool
    {
        $company = $this->channel->company;

        $from = (string) (
            $company->get(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value)
            ?? $company->get(TwilioConfigurationEnum::TWILIO_PHONE_NUMBER->value)
            ?? ''
        );

        // The Twilio channel slug is `twilio-<phone>`, so the recipient number lives there — no Lead.
        $to = Str::toE164(str_replace('twilio-', '', (string) $this->channel->slug));

        if ($from === '' || $to === '') {
            return false;
        }

        TwilioClient::getInstanceByCompany($company)
            ->messages
            ->create($to, ['from' => $from, 'body' => $this->text]);

        return true;
    }

    private function latestMessage(): ?Message
    {
        /** @var Message|null $message */
        $message = $this->channel->messages()->orderByDesc('messages.id')->first();

        return $message;
    }
}
