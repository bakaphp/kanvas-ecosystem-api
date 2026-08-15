<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Slack\Client as SlackClient;
use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\WaSender\Services\MessageService as WaSenderMessageService;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
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
        private readonly ?string $sessionUuid = null,
        // The session's canal_id — the connector's exact-case destination address (Slack
        // `slack:{team}:{channel}:{thread}`, WhatsApp `{phone}@s.whatsapp.net`). Set by every connector
        // webhook when the session is created; the channel slug can't be used (it's lowercased).
        private readonly ?string $canalId = null,
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

        $this->writeToConversation();
        $this->broadcastToChat();

        try {
            return $this->pushNative();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The in-app chat renders from `agent_conversations`, not the Social feed — so mirror the message
     * there too (the same store `logTurn` writes to), as an assistant message on the session's existing
     * conversation. Best-effort; no-op without an agent/session or when the live chat hasn't created a
     * conversation for that session yet.
     */
    private function writeToConversation(): void
    {
        if ($this->agent === null || $this->sessionUuid === null || $this->sessionUuid === '') {
            return;
        }

        try {
            new KanvasConversationStore()->appendAssistantMessageForSession(
                appsId: $this->channel->apps_id,
                companiesId: $this->channel->companies_id,
                sessionId: $this->sessionUuid,
                agentClass: $this->agent->type?->handler ?? $this->agent::class,
                content: $this->text,
                agentId: $this->agent->getId(),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Push the message into the live in-app chat the same way a normal agent turn does, so it
     * appears without a refresh. Best-effort — a broadcast outage must never fail the delivery.
     * No-op without an agent/session, where nothing is listening.
     */
    private function broadcastToChat(): void
    {
        if ($this->agent === null || $this->sessionUuid === null || $this->sessionUuid === '') {
            return;
        }

        try {
            AgentChatResponseEvent::dispatch(
                $this->agent,
                $this->sessionUuid,
                '',
                $this->text
            );

            // Disabled with the rest of the `agentChatResponse` subscription — see AgentChatKernel.
            // Needs `Nuwave\Lighthouse\Execution\Utils\Subscription` imported back to revive.
            //
            // Subscription::broadcast('agentChatResponse', [
            //     'agent_id' => $this->agent->getId(),
            //     'agent_name' => $this->agent->name,
            //     'session_id' => $this->sessionUuid,
            //     'message' => '',
            //     'response' => $this->text,
            // ]);
        } catch (Throwable $e) {
            report($e);
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

        [$slackChannelId, $threadTs] = $this->slackTargetFromCanalId();
        if ($slackChannelId === '') {
            return false;
        }

        SlackClient::getInstanceByAgent($this->agent)
            ->postMessage($slackChannelId, $this->text, $threadTs !== '' ? $threadTs : null);

        return true;
    }

    /**
     * @return array{0: string, 1: string} [slackChannelId, threadTs] parsed from the session canal_id
     *                                      `slack:{team}:{channel}:{thread_ts}` (ids are case-sensitive,
     *                                      so we take them verbatim — never from the lowercased slug)
     */
    private function slackTargetFromCanalId(): array
    {
        if ($this->canalId === null || ! str_starts_with($this->canalId, 'slack:')) {
            return ['', ''];
        }

        $parts = explode(':', $this->canalId);

        return [$parts[2] ?? '', $parts[3] ?? ''];
    }

    private function pushWhatsApp(): bool
    {
        if ($this->canalId === null || ! str_contains($this->canalId, '@s.whatsapp.net')) {
            return false;
        }

        $to = str_replace('@s.whatsapp.net', '', $this->canalId);

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
}
