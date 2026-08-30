<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Listeners;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Agents\Contracts\HandlesAgentMention;
use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentConversationBudget;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Events\MessageMentionsStoredEvent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Throwable;

/**
 * Social parsed the mentions; here we react to the ones that are agent-users. Each mentioned user that
 * resolves to an agent in the message's tenant gets its own reply.
 *
 * The entity the mention lives on decides how the agent is woken: if that entity implements
 * HandlesAgentMention (e.g. a Project or Plan running its own PM loop) we dispatch the wake it returns;
 * everything else falls through to the default per-channel reply (RespondToMentionJob). This keeps the
 * listener domain-agnostic — a new mentionable entity (Order, Product, …) needs no change here.
 */
class RespondToAgentMentionListener
{
    private const int ATTACHMENT_SETTLE_SECONDS = 6;

    public function handle(MessageMentionsStoredEvent $event): void
    {
        $message = $event->message;
        $payload = $message->message;

        // Project-ingest messages (transcript/email/mention) already wake the PM via
        // IngestToProjectAction — don't let a mention inside their content wake it a second time.
        if (is_array($payload) && isset($payload['ingest_type'])) {
            return;
        }

        // An agent naming another agent used to be dropped outright, as a blanket anti-loop guard. It
        // took the working half with it: a worker that finished step 1 and handed off to step 2 was
        // told to @mention the next agent, and that mention reached nobody (plan 25148 — task #11363
        // sat `pending` while the summary it needed was already on the board). Whether a handoff
        // worked came down to which code path posted it: a board comment carries `from_agent` and got
        // through, a mention reply carries `from_ia` and did not.
        //
        // The budget is the guard now — the same one the plan board uses. An agent-to-agent exchange
        // gets AgentConversationBudget::MAX_HOPS and then stops; a human speaking resets it, because a
        // person re-entering is the signal the conversation is wanted.
        /** @var Channel|null $channel */
        $channel = $message->channels()->first();

        if ($this->postedByAgent($message)) {
            if (! AgentConversationBudget::spend($channel)) {
                return;
            }
        } else {
            AgentConversationBudget::reset($channel);
        }

        $awaitingUpload = ! $message->files()->exists();

        // The thread as [self, parent, …, root] via AsTree's materialized path (one query). The entity
        // the mention routes to may be on THIS message OR any ancestor, and a routed reply threads under
        // the root (last element) so it stays flat.
        $ancestors = $message->joinAncestors();
        $ancestors->load('channels');
        $threadRootId = (int) $ancestors->last()->getId();
        $routableEntities = $this->routableEntities($ancestors);

        foreach ($event->mentionedUserIds as $userId) {
            $agent = Agent::fromUser(
                $userId,
                $message->app,
                $message->company
            );

            if ($agent === null) {
                continue;
            }

            // Let the mentioned entity own the routing (e.g. a Project/Plan drives its PM loop with full
            // context + board tools + ledger). The generic responder is skipped so the agent never
            // double-answers. Only the entity decides eligibility (a Project routes only for its PM).
            $entityRoute = $this->routeThroughEntities(
                $routableEntities,
                $agent,
                $message,
                $threadRootId
            );
            if ($entityRoute !== null) {
                dispatch($entityRoute);

                continue;
            }

            $job = RespondToMentionJob::dispatch($agent, $message);

            if ($awaitingUpload) {
                $job->delay(
                    now()->addSeconds(self::ATTACHMENT_SETTLE_SECONDS)
                );
            }
        }
    }

    /**
     * Whether a machine wrote this, read off the message rather than inferred from its author.
     *
     * Both stamps count: an agent's board comment carries `from_agent`, a mention reply carries
     * `from_ia`, and they are equally machine-authored. Author identity cannot answer it — agents
     * share users with real people, so `Agent::fromUser()` calls a human's message agent-authored and
     * would ration the one participant whose speaking is supposed to reset the budget.
     */
    private function postedByAgent(Message $message): bool
    {
        $payload = $message->message;

        return is_array($payload)
            && (($payload['from_agent'] ?? false) === true || ($payload['from_ia'] ?? false) === true);
    }

    /**
     * The first route an entity along the thread wants for this agent, or null to use the default
     * responder. Entities are tried in thread order (self before ancestors, project channel before any
     * other on the same message).
     *
     * @param list<HandlesAgentMention> $entities
     */
    private function routeThroughEntities(array $entities, Agent $agent, Message $mention, int $threadRootId): ?object
    {
        foreach ($entities as $entity) {
            $route = $entity->handleAgentMention(
                $agent,
                $mention,
                $threadRootId
            );

            if ($route !== null) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Every entity carried by the channels of the mention message or any thread ancestor that opts into
     * mention routing — deduped, in thread order. Ancestors count because a threaded reply carries only
     * parent_id and often isn't re-tagged onto the entity's channel.
     *
     * @param iterable<Message> $ancestors
     *
     * @return list<HandlesAgentMention>
     */
    private function routableEntities(iterable $ancestors): array
    {
        $entities = [];
        $seen = [];

        foreach ($ancestors as $node) {
            /** @var Channel $channel */
            foreach ($node->channels as $channel) {
                $namespace = $channel->entity_namespace;
                $id = $channel->entity_id;

                if ($namespace === null || $namespace === '' || $id === null) {
                    continue;
                }

                // Dedup on (namespace, id) BEFORE hitting the DB — the same entity is carried on many
                // ancestors/channels of a thread, and we only need to resolve it once.
                $key = $namespace . ':' . $id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $entity = $this->resolveChannelEntity($namespace, (int) $id);
                if ($entity instanceof HandlesAgentMention) {
                    $entities[] = $entity;
                }
            }
        }

        return $entities;
    }

    /**
     * The model a channel's (entity_namespace, entity_id) points at, or null when the class no longer
     * resolves.
     */
    private function resolveChannelEntity(string $namespace, int $id): ?Model
    {
        $class = SystemModules::convertLegacySystemModules($namespace);

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        try {
            return $class::query()->where('id', $id)->first();
        } catch (Throwable) {
            return null;
        }
    }
}
