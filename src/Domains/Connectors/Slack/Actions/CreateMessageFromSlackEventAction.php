<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Exceptions\SlackIdentityUnavailableException;
use Kanvas\Connectors\Slack\Services\SlackConversationChannelService;
use Kanvas\Connectors\Slack\Services\SlackFileAttachmentService;
use Kanvas\Connectors\Slack\Services\SlackMarkdownService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * One Slack event from the agent's app becomes one row on the Kanvas channel that mirrors the Slack
 * conversation. Every message in a room the bot was invited to comes through here; only the ones
 * addressed to the agent (a DM, a group DM, a tag) are allowed to wake it, and only those fire the
 * workflows that do so.
 */
class CreateMessageFromSlackEventAction extends BaseCreateMessageFromSlackAction
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        protected readonly ReceiverWebhook $receiver,
        protected readonly Agent $agent,
        protected readonly string $teamId,
        protected readonly array $event,
        protected readonly bool $addressed = true,
        protected readonly array $tags = [],
        protected readonly ?Carbon $occurredAt = null,
    ) {
    }

    public function execute(): ?Message
    {
        $client = Client::getInstanceByAgent($this->agent);
        $slackChannelId = (string) ($this->event['channel'] ?? '');
        $isDirectMessage = ($this->event['channel_type'] ?? '') === 'im';
        $text = $this->text();
        $files = $this->event['files'] ?? [];

        if (! $this->addressed && trim($text) === '' && $files === []) {
            return null;
        }

        try {
            $speaker = $this->resolveSpeaker($client, (string) ($this->event['user'] ?? ''));
        } catch (SlackIdentityUnavailableException) {
            // We couldn't reach Slack to identify the sender (rate limit / transient API error).
            // In a DM there's no identity to proceed on, so skip this turn silently — the next
            // message resolves, and a successful lookup is cached. Never post the "get invited"
            // message here: that misreads a transient failure as a missing account. In a room the
            // speaker is only used for attribution, so record it anyway with an unknown speaker.
            if ($isDirectMessage) {
                return null;
            }

            $speaker = null;
        }

        if ($isDirectMessage && $speaker === null) {
            // Slack knows this person but no Kanvas account matches their email — a genuine "not
            // invited". Without a Kanvas identity there's no entity to hang the conversation on,
            // and no basis to trust them with the agent.
            $client->postMessage(
                $slackChannelId,
                "I can't find a Kanvas account for your Slack email. Ask an admin to invite you, then message me again.",
                (string) ($this->event['thread_ts'] ?? ''),
            );

            return null;
        }

        $channel = $this->resolveChannel($isDirectMessage ? $speaker : null);

        if (! $isDirectMessage) {
            $this->nameChannelAfterSlack($channel, $client, $slackChannelId);
        }

        $author = $speaker ?? $this->receiver->user;

        $message = $this->writeMessage(
            channel: $channel,
            author: $author,
            payload: $this->basePayload(
                $this->attributed($text, $isDirectMessage, $speaker),
                $slackChannelId,
                $this->event,
                $speaker,
            ),
            type: $this->messageType('slack'),
            isPublic: $this->addressed ? 1 : 0,
            fireWorkflows: $this->addressed,
            parent: $this->threadRoot($channel, $this->event),
            occurredAt: $this->occurredAt,
            entity: $isDirectMessage ? $speaker : $channel,
        );

        $message->addTag($this->addressed ? 'engagement' : self::INGEST_TAG);

        foreach ($this->tags as $tag) {
            $message->addTag($tag);
        }

        $channel->addCategory('ai-agent', $this->receiver->app, $author, $this->receiver->company);

        SlackFileAttachmentService::attachAll($message, $client, $files);

        return $message;
    }

    #[Override]
    protected function receiver(): ReceiverWebhook
    {
        return $this->receiver;
    }

    #[Override]
    protected function teamId(): string
    {
        return $this->teamId;
    }

    /**
     * In a room the conversation entity is the channel, not any one person, so the agent has no
     * built-in sense of who's speaking this turn. Attribute the message so it — and the stored
     * history other speakers share — knows. A DM's entity is already the user, so it needs none.
     */
    private function attributed(string $text, bool $isDirectMessage, ?Users $speaker): string
    {
        if ($isDirectMessage || $speaker === null) {
            return $text;
        }

        $speakerName = trim($speaker->firstname . ' ' . $speaker->lastname);

        return $speakerName === '' ? $text : $speakerName . ': ' . $text;
    }

    /**
     * A room or a group DM has no owner outside Slack, so it hangs off the agent's user identity — it
     * is the agent's room. A DM hangs off the human.
     */
    private function resolveChannel(?Users $human): Channel
    {
        return new SlackConversationChannelService()->channelFor(
            $this->agent,
            $human ?? $this->agent->user ?? $this->receiver->user,
            $this->teamId,
            (string) ($this->event['channel'] ?? ''),
        );
    }

    /**
     * Strip the bot's own `<@BOT>` handle — it shouldn't see itself addressed in the third person —
     * then decode the rest of Slack's wire format into Markdown the agent can actually read.
     */
    private function text(): string
    {
        $botUserId = (string) ($this->receiver->configuration[ConfigurationEnum::BOT_USER_ID->value] ?? '');
        $text = (string) ($this->event['text'] ?? '');

        if ($botUserId !== '') {
            $text = str_replace('<@' . $botUserId . '>', '', $text);
        }

        return SlackMarkdownService::fromMrkdwn($text);
    }
}
