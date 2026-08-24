<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Exceptions\SlackIdentityUnavailableException;
use Kanvas\Connectors\Slack\Services\SlackFileAttachmentService;
use Kanvas\Connectors\Slack\Services\SlackMarkdownService;
use Kanvas\Connectors\Slack\Services\SlackUserResolverService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

class CreateMessageFromSlackEventAction
{
    public function __construct(
        protected readonly ReceiverWebhookCall $webhookRequest,
        protected readonly Agent $agent,
        protected readonly array $event,
    ) {
    }

    public function execute(): ?Message
    {
        $receiver = $this->webhookRequest->receiverWebhook;
        $app = $receiver->app;
        $company = $receiver->company;

        $client = Client::getInstanceByAgent($this->agent);
        $slackChannelId = (string) ($this->event['channel'] ?? '');
        $isDirectMessage = ($this->event['channel_type'] ?? '') === 'im';
        $threadTs = $this->replyThreadTs();

        try {
            $speaker = new SlackUserResolverService($client, $app, $company)
                ->resolve((string) ($this->event['user'] ?? ''));
        } catch (SlackIdentityUnavailableException) {
            // We couldn't reach Slack to identify the sender (rate limit / transient API error).
            // In a DM there's no identity to proceed on, so skip this turn silently — the next
            // message resolves, and a successful lookup is cached. Never post the "get invited"
            // message here: that misreads a transient failure as a missing account. In a room the
            // speaker is only used for attribution, so answer anyway with an unknown speaker.
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
                $threadTs,
            );

            return null;
        }

        $channel = $this->resolveChannel($isDirectMessage ? $speaker : null);
        $entity = $isDirectMessage ? $speaker : $channel;
        $author = $speaker ?? $receiver->user;

        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                $app->getId(),
                0,
                'slack',
                'slack',
            )
        )->execute();

        // In a room the conversation entity is the channel, not any one person, so the agent has no
        // built-in sense of who's speaking this turn. Attribute the message so it — and the stored
        // history other speakers share — knows. A DM's entity is already the user, so it needs none.
        $text = $this->text();
        if (! $isDirectMessage && $speaker !== null) {
            $speakerName = trim($speaker->firstname . ' ' . $speaker->lastname);
            if ($speakerName !== '') {
                $text = $speakerName . ': ' . $text;
            }
        }

        $messageInput = new MessageInput(
            app: $app,
            company: $company,
            user: $author,
            message: [
                ...AiChatMessagePayload::from([
                    'content' => $text,
                    'from_me' => false,
                    'from_ia' => false,
                    'raw_data' => $this->event,
                    'message_id' => (string) ($this->event['ts'] ?? ''),
                    'chat_jid' => SessionChannelService::createCanalId(
                        'slack',
                        $this->teamId() . ':' . $slackChannelId . ':' . $threadTs
                    ),
                ])->toArray(),
                'slack_channel' => $slackChannelId,
                'slack_thread_ts' => $threadTs,
                'slack_user' => $this->event['user'] ?? null,
                'slack_team' => $this->teamId(),
            ],
            type: $messageType,
            is_public: 1,
        );

        $message = new CreateMessageAction(
            $messageInput,
            SystemModulesRepository::getByModelName($entity::class, $app),
            $entity->getId(),
        )->execute();

        $channel->addMessage($message);
        $message->addTag('engagement');
        $channel->addCategory('ai-agent', $app, $author, $company);

        SlackFileAttachmentService::attachAll($message, $client, $this->event['files'] ?? null);

        return $message;
    }

    /**
     * A room has no owner outside Slack, so it hangs off the agent's user identity — it is the
     * agent's room. A DM hangs off the human.
     */
    private function resolveChannel(?Users $human): Channel
    {
        $receiver = $this->webhookRequest->receiverWebhook;
        $owner = $human ?? $this->agent->user ?? $receiver->user;
        $slackChannelId = (string) ($this->event['channel'] ?? '');

        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $receiver->app,
                companies: $receiver->company,
                users: $owner,
                entity_id: $owner->getId(),
                entity_namespace: Users::class,
                name: 'Slack ' . $slackChannelId,
                description: 'Slack conversation with ' . $this->agent->name,
                slug: SessionChannelService::createChannelSlug(
                    'slack',
                    $this->teamId() . '-' . $slackChannelId
                ),
            ),
        )->execute();

        $channel->set(IntelligenceConfigurationEnum::AGENT_CHANNEL_TYPE->value, 'SLACK');
        $channel->set(ConfigurationEnum::AGENT_ID->value, $this->agent->getId());

        return $channel;
    }

    private function teamId(): string
    {
        return (string) ($this->webhookRequest->payload['team_id'] ?? '');
    }

    /**
     * Reply where the human spoke: in their thread if they opened one, otherwise at the top level —
     * for DMs and channels alike. We never force a channel reply into its own thread, so the
     * conversation stays in the main channel unless the user themselves threaded it.
     *
     * Empty string = post at the top level (the client drops an empty thread_ts).
     */
    private function replyThreadTs(): string
    {
        return (string) ($this->event['thread_ts'] ?? '');
    }

    /**
     * Strip the bot's own `<@BOT>` handle — it shouldn't see itself addressed in the third person —
     * then decode the rest of Slack's wire format into Markdown the agent can actually read.
     */
    private function text(): string
    {
        $botUserId = (string) ($this->webhookRequest->receiverWebhook->configuration[ConfigurationEnum::BOT_USER_ID->value] ?? '');
        $text = (string) ($this->event['text'] ?? '');

        if ($botUserId !== '') {
            $text = str_replace('<@' . $botUserId . '>', '', $text);
        }

        return SlackMarkdownService::fromMrkdwn($text);
    }
}
