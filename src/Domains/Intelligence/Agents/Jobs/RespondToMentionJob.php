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
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Services\AgentChannelActivity;
use Kanvas\Intelligence\Agents\Services\AgentConversationBudget;
use Kanvas\Intelligence\Agents\Services\AgentTurnResponse;
use Kanvas\Intelligence\Notifications\AgentRepliedToMentionNotification;
use Kanvas\Intelligence\Sessions\Actions\CreateSessionAction;
use Kanvas\Intelligence\Sessions\DataTransferObject\Session as SessionData;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Plan\Support\MentionHandle;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * An agent-user was @mentioned: reply as a CHILD of the mentioning message, with the whole
 * channel in context.
 *
 * ANY Kanvas Neuron agent answers, not just `SystemUserAgent` — being reachable by name wherever a
 * person can name you is the point of an agent having a user at all. Gating on one handler excludes
 * every agent `hire_agent` creates, so a PM @mentioning its worker reaches nobody and nothing errors.
 *
 * Loop-safety is the CONVERSATION BUDGET, not the `from_ia` stamp: an agent-authored mention is
 * delivered (that is how a handoff works) and charged a hop, so an exchange between two agents ends
 * on its own. An agent still never replies to a message its own user authored.
 */
final class RespondToMentionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /** How far back to look for a person to call in when an exchange stalls. */
    private const int HUMAN_LOOKBACK = 30;

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
        if (! $handler instanceof BehavesAsKanvasAgent) {
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
        // Swaps the agent's history to this channel's thread. Only SystemUserAgent reads a mention
        // channel; every other agent keeps its own history and still answers the mention.
        if ($handler instanceof SystemUserAgent) {
            $handler->setMentionChannel($channel);
        }

        // Tools that outlive the turn (schedule_reminder, schedule_agent_task) need the channel so
        // what they create can be delivered back HERE later, and the mentioning human so "remind me"
        // means the person who asked — not the agent's own user, which is what `user:` above is.
        $handler->setSession($this->resolveChannelSession($channel, $app, $company, $subjectEntity ?? $agentUser));
        $handler->setConversationHuman(Users::getById($this->mentionMessage->users_id));

        // Silence is only an option when the counterpart is another agent — a person who asks a
        // question and gets nothing has simply been ignored.
        $mentioner = Agent::fromUser((int) $this->mentionMessage->users_id, $app, $company);
        $mentionText = $this->mentionMessage->contentText()
            . ($mentioner !== null ? AgentTurnResponse::noOpGuidance() : '');

        // RunNeuronChatAction sniffs each URL's bytes and rides image/audio/PDF/text natively, so the
        // image/document split doesn't matter here — hand it the merged list. Without this the file the
        // user attached to the @mention is dropped and the agent answers "I can't read the file."
        ['images' => $images, 'documents' => $documents] = $this->mentionMessage->attachmentUrls();

        // Baseline the channel: the agent can write here mid-turn with a board tool, and its reply
        // would then say the same thing again seconds later.
        $activityBefore = AgentChannelActivity::latestMessageId($channel);

        // session stays null HERE on purpose: the handler has it (above) for its tools, but handing it
        // to the chat action would also run its lead-channel message backfill, which would sweep this
        // channel's internal comments into the lead's message rollup.
        $reply = new RunNeuronChatAction(
            agent: $this->agent,
            session: null,
            message: $mentionText,
            app: $app,
            user: $agentUser,
            handler: $handler,
            media: [...$images, ...$documents],
        )->execute();

        // An agent that answers an acknowledgement with an acknowledgement is the loop the budget was
        // added to survive; this is what stops it being spent on nothing in the first place.
        if (trim($reply) === '' || ($mentioner !== null && AgentTurnResponse::isNoOp($reply))) {
            return;
        }

        // The agent already answered on this channel with a board tool during the turn — on plan 26531
        // that produced two messages three seconds apart, the reply restating the comment. The comment
        // is the answer; posting the turn as well is the duplicate.
        if (AgentChannelActivity::agentPostedSince($channel, $activityBefore, $agentUser)) {
            return;
        }

        $replyMessage = $this->persistChildReply(
            $channel,
            $reply,
            $agentUser,
            $subjectEntity,
        );

        $this->notifyMentioner($replyMessage, $mentioner);
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
     * The Session for this channel, reusing the one the in-app chat / connectors already key on
     * (`buildChannelSessionUuid`) so a reminder scheduled from a mention and one scheduled from the
     * chat land on the same conversation. Created on first mention; content is left to the marker
     * below rather than CreateContentSessionAction, whose entity `match` only covers
     * Lead/People/Users/Channel and would fatal on any other record a channel can hang off.
     */
    private function resolveChannelSession(
        Channel $channel,
        Apps $app,
        Companies $company,
        Model $entity,
    ): ?Session {
        try {
            $existing = Session::query()
                ->where('uuid', SessionChannelService::buildChannelSessionUuid($channel, $app, $company))
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return new CreateSessionAction(
                new SessionData(
                    app: $app,
                    company: $company,
                    agent: $this->agent,
                    entity_namespace: $entity::class,
                    entity_id: (string) $entity->getKey(),
                    user: [],
                    channel: $channel,
                    content: ['source' => 'agent-mention'],
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
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

    /**
     * Tell whoever asked that the answer has landed.
     *
     * A person gets a notification. An AGENT does not read notifications — one addressed to an agent's
     * user lands in a table nobody consults, which is how a PM asked its worker for a status, got a
     * reply on the board, and never knew. So an agent mentioner is WOKEN with the reply instead.
     *
     * That closes a cycle — it asks, we answer, it wakes, it may ask again — so every hop is charged
     * to the thread's budget. Out of budget the exchange stops and says so on the board, rather than
     * running until a wake budget somewhere else notices.
     */
    private function notifyMentioner(Message $replyMessage, ?Agent $mentioner): void
    {
        // Load the concrete Users model (Message->user is a UserFullTableName variant) so the
        // notification routes and the notifiable class is the canonical one.
        $recipient = Users::getById($this->mentionMessage->users_id);

        if ($mentioner === null) {
            // A person is in the exchange, so it is wanted — hand the pair their budget back.
            AgentConversationBudget::reset($replyMessage->channels()->first());

            $recipient->notify(
                new AgentRepliedToMentionNotification($replyMessage, $this->agent)
            );

            return;
        }

        $channel = $replyMessage->channels()->first();

        // An agent-to-agent mention is now routed by RespondToAgentMentionListener, which charges the
        // same budget. If this reply NAMES the mentioner, that path already wakes them — threaded, and
        // once. Waking them here as well is the same turn delivered twice.
        if (MentionHandle::isNamedIn($replyMessage->contentText(), $mentioner->user, $this->agent->app)) {
            return;
        }

        if (! AgentConversationBudget::spend($channel)) {
            if (AgentConversationBudget::claimStopNotice($channel)) {
                // No @ on the names: a stop notice is not an ask, and an agent name is not a handle —
                // writing one would read as a mention that reaches nobody.
                $this->postStopNotice($channel, $replyMessage, $mentioner->name);
            }

            return;
        }

        RespondToMentionJob::dispatch($mentioner, $replyMessage);
    }

    private function postStopNotice(?Channel $channel, Message $replyMessage, string $mentionerName): void
    {
        if ($channel === null) {
            return;
        }

        new PostChannelMessageAction(
            channel: $channel,
            author: $this->agent->user,
            verb: 'agent',
            content: trim(sprintf(
                '%s %s and I have gone back and forth %d times here without resolving it, so I am '
                . 'stopping. Reply and we will pick it up again.',
                $this->callForAHuman($channel),
                $mentionerName,
                AgentConversationBudget::MAX_HOPS,
            )),
            extraPayload: ['from_ia' => true, 'from_me' => true],
            parentId: $replyMessage->joinAncestors()->last()->getId(),
        )->execute();
    }

    /**
     * `@handle` of the last person who actually spoke here, or '' when there is none.
     *
     * "A human should take a look" notifies nobody on its own, and the channel's OWNER is no help —
     * on an agent-created plan board that is the agent itself, so tagging it re-tags the loop. The
     * last human to participate is the one in this conversation. Silence beats a fake mention: an
     * unmentionable name written with an `@` reads as a notification and sends none.
     */
    private function callForAHuman(Channel $channel): string
    {
        $recent = $channel->messages()
            ->orderByDesc('messages.id')
            ->limit(self::HUMAN_LOOKBACK)
            ->get();

        foreach ($recent as $message) {
            if (AgentChannelActivity::isAgentAuthored($message)) {
                continue;
            }

            $handle = MentionHandle::forUser(Users::getById((int) $message->users_id), $this->agent->app);

            if ($handle !== null) {
                return '@' . $handle;
            }
        }

        return '';
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
