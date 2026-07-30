<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Contracts;

/**
 * Marks a Neuron agent whose default conversation partner is a Kanvas Users
 * (a system agent talking to a person), rather than a CRM Lead/People it was
 * dropped onto. Distinguishes DM-style agents from entity-rollup sales agents
 * so callers (e.g. the ConverseWithSystemAgentAction funnel) can key sessions
 * and persistence on the user thread instead of an entity timeline.
 */
interface ConversesWithUser
{
}
