<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Pre-this-PR, gateway tokens lived on company config + deployment custom field. After,
 * `BaseLaunchAgentOnMachineAction::resolveGatewayToken()` only reads from the agent custom
 * field. This backfill hoists the existing deployment-level token onto the agent so an
 * existing agent's next re-launch reuses the same value instead of generating a fresh
 * random one (which would diverge from the still-running container's API_SERVER_KEY).
 * Idempotent; container env is not touched — operator must re-launch for the new env to apply.
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
