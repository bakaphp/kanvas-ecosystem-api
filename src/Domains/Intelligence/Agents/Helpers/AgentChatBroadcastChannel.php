<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Helpers;

use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Keyed on the AGENT's tenant, not the caller's — a global agent carries `companies_id = 0`, so a
 * client building this from its own company would subscribe to a channel nobody publishes to.
 * Hence `userChat` returns the finished name rather than letting clients assemble it.
 */
final class AgentChatBroadcastChannel
{
    public const string RESPONSE_EVENT = 'agent.chat.response';
    public const string FAILED_EVENT = 'agent.chat.failed';

    public static function nameFor(Agent $agent, string $sessionId): string
    {
        return 'agent-chat-' . $agent->apps_id . '-' . $agent->companies_id . '-' . $sessionId;
    }
}
