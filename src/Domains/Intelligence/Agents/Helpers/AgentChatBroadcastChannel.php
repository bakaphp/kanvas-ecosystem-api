<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Helpers;

use Baka\Support\Str;
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

    /**
     * A session id can be an email-derived channel slug, so it carries characters Pusher rejects.
     * Sanitizing here rather than at the broadcast keeps publisher and subscriber in sync — the
     * `userChat` mutation hands clients the channel name from this same method.
     */
    public static function nameFor(Agent $agent, string $sessionId): string
    {
        return Str::sanitizeChannelName(
            'agent-chat-' . $agent->apps_id . '-' . $agent->companies_id . '-' . $sessionId
        );
    }
}
