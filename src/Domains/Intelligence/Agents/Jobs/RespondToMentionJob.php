<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Jobs;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\Actions\Chat\RunNeuronChatAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Notifications\AgentRepliedToMentionNotification;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * An agent-user was @mentioned: reply as a CHILD of the mentioning message, with the whole
 * channel in context. Neuron system agents (SystemUserAgent) only, for now.
 *
 * Loop-safe: the reply is from_ia, which the mention parser skips, so agent replies never
 * re-trigger; and an agent never replies to a message its own user authored.
 */
final class RespondToMentionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Agent $agent,
        public readonly Message $mentionMessage,
    ) {
    }

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById($this->agent->apps_id);
        $this->overwriteAppService($app);

        $company = Companies::getById($this->agent->companies_id);
        $agentUser = $this->agent->user;

        if ($agentUser === null || $this->mentionMessage->users_id === $agentUser->getId()) {
            return;
        }

        /** @var Channel|null $channel */
        $channel = $this->mentionMessage->channels()->first();
        if ($channel === null) {
            return;
        }

        $handlerClass = $this->agent->type?->handler;
        if ($handlerClass === null || ! class_exists($handlerClass)) {
            return;
        }

        $handler = new $handlerClass();
        if (! $handler instanceof SystemUserAgent) {
            return;
        }

        // The mention comment usually isn't linked to an entity itself — fall back to the
        // channel's entity (e.g. the Lead the channel lives on) so the agent gets its context.
        $subjectEntity = $this->mentionMessage->entity() ?? $this->resolveChannelEntity($channel);

        $handler->setConfiguration(
            agent: $this->agent,
            entity: $subjectEntity ?? $agentUser,
            user: $agentUser,
        );
        $handler->setMentionChannel($channel);

        $mentionText = $this->mentionMessage->contentText();

        // RunNeuronChatAction sniffs each URL's bytes and rides image/audio/PDF/text natively, so the
        // image/document split doesn't matter here — hand it the merged list. Without this the file the
        // user attached to the @mention is dropped and the agent answers "I can't read the file."
        ['images' => $images, 'documents' => $documents] = $this->mentionMessage->attachmentUrls();

        $reply = new RunNeuronChatAction(
            agent: $this->agent,
            session: null,
            message: $mentionText,
            app: $app,
            user: $agentUser,
            handler: $handler,
            media: [...$images, ...$documents],
        )->execute();

        if (trim($reply) === '') {
            return;
        }

        $replyMessage = $this->persistChildReply(
            $channel,
            $reply,
            $agentUser,
            $subjectEntity,
        );

        $this->notifyMentioner($replyMessage);
        $this->recordInteractionInLedger(
            $app,
            $company,
            $subjectEntity,
            $channel,
            $reply
        );
    }

    private function persistChildReply(
        Channel $channel,
        string $reply,
        Users $agentUser,
        ?Model $subjectEntity,
    ): Message {
        return new PostChannelMessageAction(
            channel: $channel,
            author: $agentUser,
            verb: 'agent',
            content: $reply,
            extraPayload: ['from_ia' => true, 'from_me' => true],
            // Thread stays flat: anchor the reply to the thread root (AsTree self+ancestors, root last).
            parentId: $this->mentionMessage->joinAncestors()->last()->getId(),
            runWorkflow: true,
            entity: $subjectEntity,
        )->execute();
    }

    /**
     * The channel a mention lives on carries the entity (Lead, People, …) even when the mention
     * comment itself isn't linked to one — resolve it so the agent replies with full context.
     */
    private function resolveChannelEntity(Channel $channel): ?Model
    {
        if ($channel->entity_namespace === null || $channel->entity_namespace === '' || $channel->entity_id === null) {
            return null;
        }

        $class = SystemModules::convertLegacySystemModules($channel->entity_namespace);

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        try {
            return $class::getById($channel->entity_id);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    private function notifyMentioner(Message $replyMessage): void
    {
        // Load the concrete Users model (Message->user is a UserFullTableName variant) so the
        // notification routes and the notifiable class is the canonical one.
        $recipient = Users::getById($this->mentionMessage->users_id);

        $recipient->notify(
            new AgentRepliedToMentionNotification($replyMessage, $this->agent)
        );
    }

    private function recordInteractionInLedger(
        Apps $app,
        Companies $company,
        ?Model $subjectEntity,
        Channel $channel,
        string $reply,
    ): void {
        try {
            new AppendEventAction(
                new EventData(
                    app: $app,
                    company: $company,
                    sourceDomain: 'Intelligence',
                    eventType: 'agent.mention.replied',
                    status: EventStatusEnum::INFO,
                    sourceEntityType: $subjectEntity !== null ? $subjectEntity::class : null,
                    sourceEntityId: $subjectEntity !== null ? (int) $subjectEntity->getKey() : null,
                    actorType: 'Agent',
                    actorId: $this->agent->getId(),
                    payload: [
                        'summary' => Str::limit($reply, 200),
                        'mentioned_by_users_id' => $this->mentionMessage->users_id,
                        'channel_id' => $channel->getId(),
                    ],
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
