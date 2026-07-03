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

class CheckApiHealthAction extends BaseCheckHealthAction
{
    #[Override]
    protected function probe(Agent $agent): HealthCheckResultEnum
    {
        $machine = $this->deployment->machine;
        if ($machine === null) {
            return HealthCheckResultEnum::FAILED;
        }

        $token = (string) ($agent->get(CustomFieldEnum::HERMES_GATEWAY_TOKEN->value) ?? '');
        if ($token === '') {
            return HealthCheckResultEnum::FAILED;
        }

        // fromMachine() throws on an unreachable sshd — keep it inside the try so a dead machine
        // returns FAILED instead of escaping to the command's report() every tick.
        $client = null;

        try {
            $client = SshClient::fromMachine($machine);
            $command = sprintf(
                'docker exec %s curl -s -o /dev/null -m 3 -w "%%{http_code}" -H %s http://127.0.0.1:8642/health',
                escapeshellarg($this->deployment->container_name),
                escapeshellarg('Authorization: Bearer ' . $token),
            );
            $code = trim($client->exec($command, 10));
        } catch (Throwable) {
            return HealthCheckResultEnum::FAILED;
        } finally {
            $client?->disconnect();
        }

        return $code === '200' ? HealthCheckResultEnum::OK : HealthCheckResultEnum::FAILED;
    }
}
