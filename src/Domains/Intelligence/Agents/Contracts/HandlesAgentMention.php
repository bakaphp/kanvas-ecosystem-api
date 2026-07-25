<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Contracts;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;

/**
 * An entity a mentioned agent can be routed to work on. When an agent is @mentioned on this entity's
 * channel (or a thread rooted on it), the mention listener asks the entity how it wants the agent woken
 * instead of hard-coding per-domain routing — so new mentionable entities (Order, Product, …) don't
 * touch the Intelligence listener at all: they either implement this to run their own agent loop, or
 * implement nothing and fall through to the default per-channel mention reply.
 *
 * Implementations own their eligibility: e.g. a Project only routes to its execution loop when the
 * mentioned agent is its PM, otherwise it returns null and the default responder answers.
 */
interface HandlesAgentMention
{
    /**
     * The queued job to dispatch for this agent's mention on this entity, or null to fall through to
     * the default mention responder. The return is dispatched as-is by the listener.
     *
     * @param int $threadRootId the message the reply should thread under (kept flat at the thread root)
     */
    public function handleAgentMention(
        Agent $agent,
        Message $mention,
        int $threadRootId
    ): ?object;
}
