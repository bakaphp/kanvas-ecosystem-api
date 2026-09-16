<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\RebuildAgentToolInstructionsAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;

/**
 * Idempotent per-agent tool toggle — the one place a tool is turned on or off for an agent.
 *
 * Two pivots move together here: `nervous_system_agent_tools` is the governed grant record, and
 * `nervous_system_agent_selected_tools` is what the runtime actually reads. Calling
 * GrantToolToAgentAction alone writes the first and leaves the agent without the tool, silently — so the
 * GraphQL toggle and connecting an MCP server both come through this action.
 */
class SetAgentToolAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly Tool $tool,
        protected readonly bool $enabled,
        protected readonly Users $actor,
        protected readonly ?array $config = null,
    ) {
    }

    /**
     * An MCP server is unaudited third-party surface. Reaching a prospect, it could be talked into acting
     * on the company's Jira — so the grant is refused where an admin is told why. The resolver filters
     * it again at runtime, because the marker can be added to an agent that already holds the grant.
     */
    public static function assertMayHold(Agent $agent, Tool $tool): void
    {
        if ($tool->isMcp() && $agent->conversesWithCustomer()) {
            throw new ValidationException(sprintf(
                'Agent "%s" talks to customers, so it cannot be given the MCP server "%s".',
                $agent->name,
                $tool->name,
            ));
        }
    }

    public function execute(): AgentTool
    {
        if ($this->enabled) {
            self::assertMayHold($this->agent, $this->tool);
        }

        // withTrashed so soft-deleted rows are visible: toggling off then on must reactivate the same row, not insert a duplicate.
        $existing = AgentTool::query()
            ->withTrashed()
            ->where('agent_id', $this->agent->getId())
            ->where('tool_id', $this->tool->getId())
            ->first();

        if ($this->enabled) {
            $grant = new GrantToolToAgentAction(
                agent: $this->agent,
                tool: $this->tool,
                grantedByUserId: $this->actor->getId(),
                config: $this->config,
            )->execute();

            $this->agent->selectedTools()->syncWithoutDetaching([$this->tool->getId()]);
            new RebuildAgentToolInstructionsAction($this->agent, $this->agent->app)->execute();

            return $grant;
        }

        $this->agent->selectedTools()->detach($this->tool->getId());
        new RebuildAgentToolInstructionsAction($this->agent, $this->agent->app)->execute();

        if ($existing !== null && $existing->is_deleted) {
            return $existing;
        }

        if ($existing === null) {
            // Tool was only "selected" via the agent type's defaults — persist an explicit revocation so the read query subtracts it.
            return AgentTool::create([
                'apps_id' => $this->agent->apps_id,
                'companies_id' => $this->agent->companies_id,
                'agent_id' => $this->agent->getId(),
                'tool_id' => $this->tool->getId(),
                'granted_by_users_id' => $this->actor->getId(),
                'granted_at' => Carbon::now(),
                'is_active' => false,
                'is_deleted' => true,
                'config' => $this->config,
            ]);
        }

        return new RevokeToolFromAgentAction(
            grant: $existing,
            actorUserId: $this->actor->getId(),
        )->execute();
    }
}
