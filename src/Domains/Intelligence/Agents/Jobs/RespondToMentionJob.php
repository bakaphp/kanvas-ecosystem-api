<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Jobs;

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
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;

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

        $reply = new RunNeuronChatAction(
            agent: $this->agent,
            session: null,
            message: $mentionText,
            app: $app,
            user: $agentUser,
            handler: $handler,
            media: [],
        )->execute();

        if (trim($reply) === '') {
            return;
        }

        $replyMessage = $this->persistChildReply(
            $app,
            $company,
            $channel,
            $reply,
            $agentUser,
            $subjectEntity,
        );

        $this->notifyMentioner($replyMessage);
    }

    private function persistChildReply(
        Apps $app,
        Companies $company,
        Channel $channel,
        string $reply,
        Users $agentUser,
        ?Model $subjectEntity,
    ): Message {
        $replyMessage = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $agentUser,
                type: MessageTypeService::getOrCreate($app, 'agent'),
                message: ['content' => $reply, 'from_ia' => true, 'from_me' => true],
                parent_id: $this->threadRootId(),
                is_public: 1,
            ),
        )->execute();

        $channel->addMessage($replyMessage, $agentUser);

        if ($subjectEntity !== null) {
            $replyMessage->addEntity($subjectEntity);
        }

        return $replyMessage;
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

    /**
     * The thread stays one level deep: a reply always anchors to the root message, even when
     * the @mention arrives inside an existing child reply.
     */
    private function threadRootId(): int
    {
        $message = $this->mentionMessage;

        while (! empty($message->parent_id) && $message->parent instanceof Message) {
            $message = $message->parent;
        }

        return $message->getId();
    }
}
