<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Exceptions\SlackIdentityUnavailableException;
use Kanvas\Connectors\Slack\Services\SlackChannelResolverService;
use Kanvas\Connectors\Slack\Services\SlackFileAttachmentService;
use Kanvas\Connectors\Slack\Services\SlackMarkdownService;
use Kanvas\Connectors\Slack\Services\SlackUserResolverService;
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
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

/**
 * The listener twin of CreateMessageFromSlackEventAction, minus everything that talks back: no
 * agent, no session, no "ask an admin to invite you" reply, no ai-agent category on the channel.
 *
 * A speaker with no Kanvas account is still recorded, attributed to the receiver's own user with
 * the raw slack_user kept on the payload — dropping those would put holes in the corpus.
 */
class CreateMessageFromSlackListenerEventAction
{
    /**
     * @param list<string> $tags extra tags for edits/deletes, which are stored as their own rows
     */
    public function __construct(
        protected readonly ReceiverWebhookCall $webhookRequest,
        protected readonly array $event,
        protected readonly array $tags = [],
    ) {
    }

    public function execute(): ?Message
    {
        $receiver = $this->webhookRequest->receiverWebhook;

        $text = trim(SlackMarkdownService::fromMrkdwn((string) ($this->event['text'] ?? '')));
        $files = $this->event['files'] ?? [];

        if ($text === '' && $files === []) {
            return null;
        }

        $client = Client::getInstanceByReceiver($receiver);
        $speaker = $this->resolveSpeaker($client, $receiver);
        $slackChannelId = (string) ($this->event['channel'] ?? '');

        $message = $this->writeMessage(
            $this->resolveChannel($client, $slackChannelId),
            $speaker ?? $receiver->user,
            $this->messagePayload($text, $slackChannelId, $speaker !== null),
        );

        $message->addTag('slack-ingest');

        foreach ($this->tags as $tag) {
            $message->addTag($tag);
        }

        if ((bool) ($receiver->configuration[ConfigurationEnum::INGEST_FILES->value] ?? false)) {
            SlackFileAttachmentService::attachAll($message, $client, $files);
        }

        return $message;
    }

    /**
     * Two independent firings have to be off for this to stay a listener: CreateMessageAction fires
     * WorkflowEnum::CREATED, and Channel::addMessage() fires its own UPDATED on top. Either one left
     * on lets a company's existing rules answer every message we record.
     */
    private function writeMessage(Channel $channel, Users $author, array $payload): Message
    {
        $receiver = $this->webhookRequest->receiverWebhook;
        $app = $receiver->app;

        $createMessage = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $receiver->company,
                user: $author,
                type: $this->messageType(),
                message: $payload,
                // Workspace chatter is internal by definition. is_public would expose an entire
                // company's Slack history through the public Social feeds.
                is_public: 0,
            ),
            SystemModulesRepository::getByModelName(Channel::class, $app),
            $channel->getId(),
        );
        $createMessage->runWorkflow = false;

        $message = $createMessage->execute();

        $channel->disableWorkflows();
        $channel->addMessage($message);

        return $message;
    }

    private function messagePayload(string $text, string $slackChannelId, bool $speakerResolved): array
    {
        $threadTs = (string) ($this->event['thread_ts'] ?? '');

        return [
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
            'slack_speaker_resolved' => $speakerResolved,
        ];
    }

    private function messageType(): MessageType
    {
        return new CreateMessageTypeAction(
            new MessageTypeInput(
                $this->webhookRequest->receiverWebhook->app->getId(),
                0,
                'slack-ingest',
                'slack-ingest',
            )
        )->execute();
    }

    /**
     * Unlike the agent path there is nobody waiting on an answer, so an unattributed row beats
     * losing the message to a transient Slack failure.
     */
    private function resolveSpeaker(Client $client, ReceiverWebhook $receiver): ?Users
    {
        try {
            return new SlackUserResolverService($client, $receiver->app, $receiver->company)
                ->resolve((string) ($this->event['user'] ?? ''));
        } catch (SlackIdentityUnavailableException) {
            return null;
        }
    }

    /**
     * Keyed on the immutable Slack id, so renaming a channel updates this row instead of forking one.
     */
    private function resolveChannel(Client $client, string $slackChannelId): Channel
    {
        $receiver = $this->webhookRequest->receiverWebhook;
        $owner = $receiver->user;

        $info = new SlackChannelResolverService(
            $client,
            $receiver->app,
            $receiver->company,
            $this->teamId(),
        )->resolve($slackChannelId);

        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $receiver->app,
                companies: $receiver->company,
                users: $owner,
                entity_id: $owner->getId(),
                entity_namespace: Users::class,
                name: $info['name'] ?? 'Slack ' . $slackChannelId,
                description: $info['description'] ?? 'Slack workspace conversation ' . $slackChannelId,
                slug: SessionChannelService::createChannelSlug(
                    'slack',
                    $this->teamId() . '-' . $slackChannelId
                ),
            ),
        )->execute();

        if ($info !== null) {
            $this->syncChannelNaming($channel, $info);
        }

        return $channel;
    }

    /**
     * CreateChannelAction only writes name/description on insert, so a row created before the name
     * was resolvable — or renamed in Slack since — keeps the stale value forever.
     */
    private function syncChannelNaming(Channel $channel, array $info): void
    {
        if ($channel->name === $info['name'] && $channel->description === $info['description']) {
            return;
        }

        $channel->name = $info['name'];
        $channel->description = $info['description'];
        $channel->saveOrFail();
    }

    private function teamId(): string
    {
        return (string) ($this->webhookRequest->payload['team_id'] ?? '');
    }
}
