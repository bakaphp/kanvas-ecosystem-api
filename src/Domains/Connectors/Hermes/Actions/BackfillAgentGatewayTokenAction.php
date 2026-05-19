<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Hoists an existing per-deployment HERMES_GATEWAY_TOKEN onto the agent that owns it.
 *
 * Background: pre-this-PR, gateway tokens were sourced from company config and written
 * to the deployment custom field on launch. After this PR, the agent custom field is the
 * canonical source and `BaseLaunchAgentOnMachineAction::resolveGatewayToken()` only reads
 * from there. Without this backfill, the next launch of any pre-existing agent regenerates
 * a fresh random token — invalidating the value baked into the running container.
 *
 * Strategy per agent (idempotent — safe to re-run):
 *   1. Skip if the agent already has HERMES_GATEWAY_TOKEN set (post-PR launches set this).
 *   2. Otherwise read the token from the most recent running deployment for that agent and
 *      copy it onto the agent. The running container's actual env matches that value, so
 *      a future re-launch produces the same compose file.
 *   3. Skip if no running deployment carries a token (the agent never launched on this
 *      runtime, or was terminated before the deployment write). Next launch will mint a
 *      fresh token anyway; that's fine.
 *
 * Does NOT touch the running container's runtime — operators must re-launch the agent for
 * the new API_SERVER_KEY env to take effect inside the existing container. This action only
 * makes sure that re-launch picks up the same token instead of generating a different one.
 */
class BackfillAgentGatewayTokenAction
{
    /**
     * @return array{updated: int, skipped_already_set: int, skipped_no_token: int}
     */
    public function execute(): array
    {
        $updated = 0;
        $skippedAlreadySet = 0;
        $skippedNoToken = 0;

        $agentIds = AgentDeployment::query()
            ->where('provider', 'hermes')
            ->where('is_deleted', 0)
            ->pluck('agent_id')
            ->unique()
            ->values();

        foreach ($agentIds as $agentId) {
            /** @var Agent|null $agent */
            $agent = Agent::query()->find($agentId);
            if ($agent === null) {
                continue;
            }

            $existing = (string) ($agent->get(CustomFieldEnum::HERMES_GATEWAY_TOKEN->value) ?? '');
            if ($existing !== '') {
                $skippedAlreadySet++;

                continue;
            }

            $token = $this->resolveTokenFromDeployments($agent);
            if ($token === null) {
                $skippedNoToken++;

                continue;
            }

            $agent->set(CustomFieldEnum::HERMES_GATEWAY_TOKEN->value, $token);
            $updated++;
        }

        return [
            'updated' => $updated,
            'skipped_already_set' => $skippedAlreadySet,
            'skipped_no_token' => $skippedNoToken,
        ];
    }

    /**
     * Walk deployments newest-first and return the first non-empty token. Running deployments
     * are preferred since their env is live; falls back to any deployment so terminated agents
     * still inherit their last-known token (preserves identity across re-launches).
     */
    private function resolveTokenFromDeployments(Agent $agent): ?string
    {
        $deployments = AgentDeployment::query()
            ->where('agent_id', $agent->getId())
            ->where('provider', 'hermes')
            ->where('is_deleted', 0)
            ->orderByRaw("CASE WHEN status = 'running' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        foreach ($deployments as $deployment) {
            $token = (string) ($deployment->get(CustomFieldEnum::HERMES_GATEWAY_TOKEN->value) ?? '');
            if ($token !== '') {
                return $token;
            }
        }

        return null;
    }
}
