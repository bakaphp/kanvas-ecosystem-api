<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Orchestrator;

use Kanvas\Intelligence\Agents\Attributes\AgentTypeDefinition;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;

/**
 * The company-level signal orchestrator. It is NOT a working PM — it never creates plans. Its whole job
 * is to receive unlabeled inbound signals (transcripts, emails, …) and route each to the right project's
 * PM. The routing itself runs deterministically in `ProcessOrchestratorSignalJob` (attendee↔member match
 * + an LLM classify), so this handler exists mainly as the identity that owns the company Inbox project
 * and stamps the orchestrator's ledger events. Synced to the global `agent_types` catalog (apps_id=0) by
 * `kanvas:intelligence:sync-agent-types`; one instance is provisioned per company.
 */
#[AgentTypeDefinition(
    name: 'Project Orchestrator',
    description: 'Routes unlabeled inbound signals (meeting transcripts, emails, …) to the right project PM. Does not do project work itself.',
    provider: 'neuron',
    soul: 'You are the project orchestrator. You receive inbound signals with no project label and decide which project each belongs to, then hand it off. You never do the project work yourself — you route.',
    outputFormat: 'Plain text.',
)]
class ProjectOrchestratorAgent extends SystemUserAgent
{
}
