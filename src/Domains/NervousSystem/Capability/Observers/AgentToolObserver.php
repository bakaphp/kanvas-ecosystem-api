<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Observers;

use Kanvas\Connectors\Mcp\Services\McpCredentialService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Intelligence\Agents\Actions\ReconcileAgentKanvasModulesAction;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Throwable;

class AgentToolObserver
{
    public function saved(AgentTool $agentTool): void
    {
        $this->reconcile($agentTool);

        if (! $agentTool->is_active || $agentTool->is_deleted) {
            $this->forgetMcpConnection($agentTool);
        }
    }

    public function deleted(AgentTool $agentTool): void
    {
        $this->reconcile($agentTool);
        $this->forgetMcpConnection($agentTool);
    }

    private function reconcile(AgentTool $agentTool): void
    {
        // Must not block the grant/revoke — a missed reconcile is recoverable
        // via the sync command.
        try {
            $agent = $agentTool->agent;
            if ($agent === null) {
                return;
            }
            new ReconcileAgentKanvasModulesAction($agent)->execute();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Turning an MCP server off deletes the agent's credential. Here rather than in the revoke path
     * because grants also end by expiry and by the revocation insert. Skipped while another active grant
     * exists: granting hard-deletes duplicate rows, and that is not a revocation.
     */
    private function forgetMcpConnection(AgentTool $agentTool): void
    {
        try {
            $tool = $agentTool->tool;
            $agent = $agentTool->agent;
            $integration = $tool?->integration;

            if ($tool === null || ! $tool->isMcp() || $agent === null || $integration === null) {
                return;
            }

            $stillHeld = AgentTool::query()
                ->where('agent_id', $agentTool->agent_id)
                ->where('tool_id', $agentTool->tool_id)
                ->active()
                ->exists();

            if ($stillHeld) {
                return;
            }

            new McpCredentialService($agent, $integration)->forget();
            new McpToolCacheService(
                agent: $agent,
                integration: $integration,
                toolVersion: $tool->version,
            )->forget();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
