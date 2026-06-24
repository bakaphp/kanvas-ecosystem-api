<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCheckHealthAction;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\AgentRuntime\Services\CliJsonExtractorService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Override;
use Throwable;

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
            $output = $client->getHealthForContainer($this->deployment->container_name);
        } catch (Throwable) {
            return HealthCheckResultEnum::FAILED;
        } finally {
            $client->disconnect();
        }

        return $this->isHealthy($output)
            ? HealthCheckResultEnum::OK
            : HealthCheckResultEnum::FAILED;
    }

    // OpenClaw mixes plain-text warnings ("Config warnings:", "[plugins]…")
    // with the JSON response — both BEFORE and sometimes AFTER the actual
    // `{…}`. Brace-count via CliJsonExtractorService so trailing notices
    // don't break the decode.
    private function isHealthy(string $output): bool
    {
        $json = CliJsonExtractorService::extractFirstObject($output);
        if ($json === null) {
            return false;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && ($decoded['ok'] ?? false) === true;
    }
}
