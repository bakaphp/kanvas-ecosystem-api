<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Actions;

use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\NativeChannelDeliveryService;
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

    /**
     * Push it out over the connector this channel came in on, so it lands in the conversation the
     * person is actually in rather than only in Kanvas's mirror of it.
     */
    private function pushNative(): bool
    {
        return NativeChannelDeliveryService::deliver(
            $this->channel,
            $this->text,
            $this->agent,
            $this->canalId,
        );
    }
}
