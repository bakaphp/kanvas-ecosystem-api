<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Slack\Client;
use Kanvas\Connectors\Slack\Exceptions\SlackIdentityUnavailableException;
use Kanvas\Connectors\Slack\Services\SlackChannelResolverService;
use Kanvas\Connectors\Slack\Services\SlackMessageLookupService;
use Kanvas\Connectors\Slack\Services\SlackUserResolverService;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
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

/**
 * What the agent app and the workspace listener share when they turn a Slack event into a row:
 * the payload shape, who the speaker is, which row a thread reply hangs under, and the write itself.
 * The two differ in everything that talks back — the agent app answers, the listener never does —
 * and those parts stay in the subclasses.
 */
abstract class BaseCreateMessageFromSlackAction
{
    public const string INGEST_TAG = 'slack-ingest';

    private ?SlackMessageLookupService $lookup = null;

    abstract protected function receiver(): ReceiverWebhook;

    abstract protected function teamId(): string;

    /**
     * A transient Slack failure is not a missing account: the caller decides whether an unknown
     * speaker is acceptable (a room, where the name is only attribution) or not (a DM).
     *
     * @throws SlackIdentityUnavailableException
     */
    protected function resolveSpeaker(Client $client, string $slackUserId): ?Users
    {
        $receiver = $this->receiver();

        return new SlackUserResolverService($client, $receiver->app, $receiver->company)->resolve($slackUserId);
    }

    protected function resolveSpeakerOrNull(Client $client, string $slackUserId): ?Users
    {
        try {
            return $this->resolveSpeaker($client, $slackUserId);
        } catch (SlackIdentityUnavailableException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function basePayload(
        string $text,
        string $slackChannelId,
        array $event,
        ?Users $speaker,
    ): array {
        $threadTs = (string) ($event['thread_ts'] ?? '');

        return [
            ...AiChatMessagePayload::from([
                'content' => $text,
                'from_me' => false,
                'from_ia' => false,
                'raw_data' => $event,
                'message_id' => (string) ($event['ts'] ?? ''),
                'chat_jid' => SessionChannelService::createCanalId(
                    'slack',
                    $this->teamId() . ':' . $slackChannelId . ':' . $threadTs
                ),
            ])->toArray(),
            'slack_channel' => $slackChannelId,
            'slack_thread_ts' => $threadTs,
            'slack_user' => $event['user'] ?? null,
            'slack_team' => $this->teamId(),
            // The raw Slack id above is what lets an unattributed row be re-attributed once the
            // person is invited.
            'slack_speaker_resolved' => $speaker !== null,
        ];
    }

    /**
     * A reply in a thread becomes a child of the root's row. A root that was never stored (the
     * thread predates the invite and the backfill did not reach it) leaves the reply top-level; the
     * Slack thread timestamp on the payload still ties it to its thread.
     */
    protected function threadRoot(Channel $channel, array $event): ?Message
    {
        $threadTs = (string) ($event['thread_ts'] ?? '');
        $ts = (string) ($event['ts'] ?? '');

        if ($threadTs === '' || $threadTs === $ts) {
            return null;
        }

        return $this->lookup()->find($channel, $threadTs);
    }

    /**
     * Workflows are the switch between "stored" and "answered": CreateMessageAction fires CREATED
     * and Channel::addMessage() fires UPDATED, and either one lets a company's rules answer every
     * row we record. Off for anything that is only being recorded.
     *
     * @param Model|null $entity what the row is about — the channel for a room, the human for a DM
     */
    protected function writeMessage(
        Channel $channel,
        Users $author,
        array $payload,
        MessageType $type,
        int $isPublic,
        bool $fireWorkflows,
        ?Message $parent = null,
        ?Carbon $occurredAt = null,
        ?Model $entity = null,
    ): Message {
        $receiver = $this->receiver();
        $app = $receiver->app;
        $entity ??= $channel;

        $createMessage = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $receiver->company,
                user: $author,
                type: $type,
                message: $payload,
                parent_id: $parent?->getId(),
                parent_unique_id: $parent?->uuid,
                is_public: $isPublic,
            ),
            SystemModulesRepository::getByModelName($entity::class, $app),
            $entity->getId(),
        );
        $createMessage->runWorkflow = $fireWorkflows;

        $message = $createMessage->execute();

        // Backfilled rows keep Slack's time, so the channel reads in the order people spoke.
        if ($occurredAt !== null) {
            $message->created_at = $occurredAt;
            $message->saveQuietly();
        }

        if (! $fireWorkflows) {
            $channel->disableWorkflows();
        }

        $channel->addMessage($message);

        $this->lookup()->remember($channel, (string) ($payload['message_id'] ?? ''), $message);

        return $message;
    }

    /**
     * CreateChannelAction only writes name/description on insert, so a row created before the name
     * was resolvable — or renamed in Slack since — keeps the stale value forever.
     */
    protected function nameChannelAfterSlack(Channel $channel, Client $client, string $slackChannelId): void
    {
        $receiver = $this->receiver();

        $info = new SlackChannelResolverService(
            $client,
            $receiver->app,
            $receiver->company,
            $this->teamId(),
        )->resolve($slackChannelId);

        if ($info === null || ($channel->name === $info['name'] && $channel->description === $info['description'])) {
            return;
        }

        $channel->name = $info['name'];
        $channel->description = $info['description'];
        $channel->saveOrFail();
    }

    protected function messageType(string $verb): MessageType
    {
        return new CreateMessageTypeAction(
            new MessageTypeInput(
                $this->receiver()->app->getId(),
                0,
                $verb,
                $verb,
            )
        )->execute();
    }

    protected function lookup(): SlackMessageLookupService
    {
        return $this->lookup ??= new SlackMessageLookupService();
    }
}
