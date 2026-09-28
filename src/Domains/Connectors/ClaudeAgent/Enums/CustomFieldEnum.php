<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Enums;

/**
 * A hosted agent has no AgentDeployment row, so the whole linkage to the vendor lives here.
 *
 * CLAUDE_AGENT_FINGERPRINT hashes the spec we last pushed: when it moves we version the remote
 * agent, when it matches we skip the call entirely.
 *
 * The GitHub token and repo allow-list are agent-scoped, not company-scoped, so each agent carries a
 * least-privilege PAT for only its own repos — same rule as the pi.dev connector.
 */
enum CustomFieldEnum: string
{
    case CLAUDE_AGENT_ID = 'CLAUDE_AGENT_ID';
    case CLAUDE_AGENT_VERSION = 'CLAUDE_AGENT_VERSION';
    case CLAUDE_AGENT_FINGERPRINT = 'CLAUDE_AGENT_FINGERPRINT';
    case CLAUDE_VAULT_ID = 'CLAUDE_VAULT_ID';
    case CLAUDE_VAULT_FINGERPRINT = 'CLAUDE_VAULT_FINGERPRINT';
    case CLAUDE_GITHUB_TOKEN = 'CLAUDE_GITHUB_TOKEN';
    case CLAUDE_ALLOWED_REPOS = 'CLAUDE_ALLOWED_REPOS';
    case CLAUDE_SESSION_BUDGET_CENTS = 'CLAUDE_SESSION_BUDGET_CENTS';
}
