<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\Enums\CustomFieldEnum;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCheckHealthAction;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;
use Throwable;

/**
 * Hermes-flavored health probe: SSH into the AgentMachine, `docker exec curl /health` against
 * port 8642 on the container's loopback, bearer = the per-agent HERMES_GATEWAY_TOKEN. State
 * machine, ledger emission, and awake_state lifecycle all live in {@see BaseCheckHealthAction}
 * — this class supplies only the probe.
 */
class CheckApiHealthAction extends BaseCheckHealthAction
{
    #[Override]
    protected function probe(): HealthCheckResultEnum
    {
        $machine = $this->deployment->machine;
        if ($machine === null) {
            return HealthCheckResultEnum::FAILED;
        }

        $agent = Agent::query()->find($this->deployment->agent_id);
        $token = (string) ($agent?->get(CustomFieldEnum::HERMES_GATEWAY_TOKEN->value) ?? '');
        if ($token === '') {
            return HealthCheckResultEnum::FAILED;
        }

        $client = SshClient::fromMachine($machine);

        try {
            $command = sprintf(
                'docker exec %s curl -s -o /dev/null -m 3 -w "%%{http_code}" -H %s http://127.0.0.1:8642/health',
                escapeshellarg($this->deployment->container_name),
                escapeshellarg('Authorization: Bearer ' . $token),
            );
            $code = trim($client->exec($command, 10));
        } catch (Throwable) {
            return HealthCheckResultEnum::FAILED;
        } finally {
            $client->disconnect();
        }

        return $code === '200' ? HealthCheckResultEnum::OK : HealthCheckResultEnum::FAILED;
    }
}
