<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCheckHealthAction;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;
use Throwable;

/**
 * Probes OpenClaw via `openclaw health --json` inside the container — exercises the gateway
 * and channel connectivity, so it catches "container up but gateway broken" that a bare
 * `docker inspect` would miss. Healthy = the top-level `ok: true` in the JSON payload.
 */
class CheckCliHealthAction extends BaseCheckHealthAction
{
    #[Override]
    protected function probe(Agent $agent): HealthCheckResultEnum
    {
        $machine = $this->deployment->machine;
        if ($machine === null) {
            return HealthCheckResultEnum::FAILED;
        }

        $client = SshClient::fromMachine($machine);

        try {
            $output = $client->exec(
                'docker exec ' . escapeshellarg($this->deployment->container_name)
                . ' openclaw health --json 2>&1',
                30,
            );
        } catch (Throwable) {
            return HealthCheckResultEnum::FAILED;
        } finally {
            $client->disconnect();
        }

        return $this->isHealthy($output)
            ? HealthCheckResultEnum::OK
            : HealthCheckResultEnum::FAILED;
    }

    /**
     * OpenClaw prints "Config warnings:" lines ahead of the JSON — decode from the first brace.
     */
    private function isHealthy(string $output): bool
    {
        $start = strpos($output, '{');
        if ($start === false) {
            return false;
        }

        $json = json_decode(substr($output, $start), true);

        return is_array($json) && ($json['ok'] ?? false) === true;
    }
}
