<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Services\SlackFileAttachmentService;
use Kanvas\Connectors\Slack\Services\SlackMarkdownService;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Override;

/**
 * The listener twin of CreateMessageFromSlackEventAction, minus everything that talks back: no
 * agent, no session, no "ask an admin to invite you" reply, no ai-agent category on the channel.
 *
 * A speaker with no Kanvas account is still recorded, attributed to the receiver's own user with
 * the raw slack_user kept on the payload — dropping those would put holes in the corpus.
 */
class CreateMessageFromSlackListenerEventAction extends BaseCreateMessageFromSlackAction
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
        $receiver = $this->receiver();

        $text = trim(SlackMarkdownService::fromMrkdwn((string) ($this->event['text'] ?? '')));
        $files = $this->event['files'] ?? [];

        if ($text === '' && $files === []) {
            return null;
        }

        $client = Client::getInstanceByReceiver($receiver);
        $speaker = $this->resolveSpeakerOrNull($client, (string) ($this->event['user'] ?? ''));
        $slackChannelId = (string) ($this->event['channel'] ?? '');
        $channel = $this->resolveChannel($slackChannelId);

        $this->nameChannelAfterSlack($channel, $client, $slackChannelId);

        $message = $this->writeMessage(
            channel: $channel,
            author: $speaker ?? $receiver->user,
            payload: $this->basePayload(
                $text,
                $slackChannelId,
                $this->event,
                $speaker,
            ),
            type: $this->messageType('slack-ingest'),
            // Workspace chatter is internal by definition. is_public would expose an entire
            // company's Slack history through the public Social feeds.
            isPublic: 0,
            fireWorkflows: false,
            parent: $this->threadRoot($channel, $this->event),
        );

        $message->addTag(self::INGEST_TAG);

        foreach ($this->tags as $tag) {
            $message->addTag($tag);
        }

        if ((bool) ($receiver->configuration[ConfigurationEnum::INGEST_FILES->value] ?? false)) {
            SlackFileAttachmentService::attachAll($message, $client, $files);
        }

        return $message;
    }

    #[Override]
    protected function receiver(): ReceiverWebhook
    {
        return $this->webhookRequest->receiverWebhook;
    }

    #[Override]
    protected function teamId(): string
    {
        return (string) ($this->webhookRequest->payload['team_id'] ?? '');
    }

    /**
     * Keyed on the immutable Slack id, so renaming a channel updates this row instead of forking one.
     */
    private function resolveChannel(string $slackChannelId): Channel
    {
        $receiver = $this->receiver();
        $owner = $receiver->user;

        return new CreateChannelAction(
            new ChannelDto(
                apps: $receiver->app,
                companies: $receiver->company,
                users: $owner,
                entity_id: $owner->getId(),
                entity_namespace: Users::class,
                name: 'Slack ' . $slackChannelId,
                description: 'Slack workspace conversation ' . $slackChannelId,
                slug: SessionChannelService::createChannelSlug(
                    'slack',
                    $this->teamId() . '-' . $slackChannelId
                ),
            ),
        )->execute();
    }
}
