<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Contracts;

/**
 * A tool only an internal system agent may hold.
 *
 * The coding harness is the case this exists for: its tools run code on a machine we operate, with a
 * git token whose reach is the agent's reach, and they push branches and open pull requests. That is
 * the company acting on its own repositories. A prospect on a WhatsApp thread who can steer the agent
 * holding them is not a support incident, it is code execution and source access.
 *
 * Gated in two places, for the reason `SetAgentToolAction` already documents for MCP servers: the
 * grant is refused where an admin is told why, and `MergesRegisteredTools` filters it again at
 * runtime, because the marker can be added to an agent that already holds the grant.
 *
 * The test is `Agent::conversesWithUser()` — the POSITIVE marker, not "is not customer-facing".
 * Elsewhere internal is the default and the gates read as a negation of `ConversesWithCustomer`, so an
 * agent declaring neither marker is treated as internal. That default is wrong here: forgetting a
 * marker would grant code execution rather than withhold it. An agent must say what it is to run code.
 */
interface RequiresSystemAgent
{
}
