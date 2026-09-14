<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Providers;

use Kanvas\Connectors\ClaudeAgent\Actions\RunSessionTurnAction;
use Kanvas\Connectors\ClaudeAgent\Services\CustomToolBridgeService;
use Kanvas\Intelligence\AgentRuntime\Providers\AbstractAgentRuntimeProvider;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Override;

/**
 * Claude Managed Agents runtime provider.
 *
 * Anthropic owns both the agent loop and the per-session sandbox, so almost none of the
 * machine-shaped contract applies: no AgentMachine, no SSH, no docker, no ports, no AgentDeployment
 * row. The linkage lives in the agent's own custom fields.
 *
 * Unimplemented ops keep AbstractAgentRuntimeProvider's default-throw rather than a fake success —
 * a caller reaching for `execCommand` here has a wrong assumption we want surfaced.
 */
class ClaudeProvider extends AbstractAgentRuntimeProvider
{
    #[Override]
    public function name(): AgentProviderEnum
    {
        return AgentProviderEnum::CLAUDE;
    }

    /**
     * `$sessionKey` is the Kanvas Session uuid. Resolving the row is what lets the remote session id
     * and event cursor ride its `content` column, so a second turn continues the same hosted
     * conversation instead of starting a new one.
     *
     * @param list<string> $images
     */
    #[Override]
    public function chat(
        Agent $agent,
        string $message,
        ?string $sessionKey = null,
        array $images = [],
        array $additionalTools = [],
    ): string {
        return new RunSessionTurnAction(
            agent: $agent,
            session: $this->resolveSession($agent, $sessionKey),
            message: $message,
            images: $images,
            bridge: new CustomToolBridgeService($agent, additionalTools: $additionalTools),
        )->execute();
    }

    protected function resolveSession(Agent $agent, ?string $sessionKey): ?Session
    {
        if ($sessionKey === null || $sessionKey === '') {
            return null;
        }

        return Session::query()
            ->where('uuid', $sessionKey)
            ->where('apps_id', $agent->apps_id)
            ->first();
    }
}
