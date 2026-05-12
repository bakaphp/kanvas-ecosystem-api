<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Tear down an agent's Docker containers and remove its Linux user.
 *
 * Idempotent — if the agent's dot-dir is already missing (partial deploy, prior manual
 * cleanup, or half-finished terminate), the docker compose step is skipped and we still
 * proceed to userdel + status update. Without this, a stuck deployment could never be
 * cleaned up via the standard terminate flow (Sentry: KANVAS-ECOSYSTEM-5C1).
 *
 * Subclasses (OpenClaw\TerminateAgentOnMachineAction, Hermes\TerminateAgentOnMachineAction)
 * supply the provider-specific SshClient + ProviderConfig.
 */
abstract class BaseTerminateAgentOnMachineAction
{
    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    abstract protected function getProviderConfig(): ProviderConfig;

    abstract protected function createSshClient(): SshClient;

    public function execute(): bool
    {
        if ($this->deployment->status === DeploymentStatusEnum::TERMINATED->value) {
            throw new ValidationException('Deployment is already terminated');
        }

        $config = $this->getProviderConfig();
        $providerDir = $this->deployment->home_directory . '/.' . $config->dotDir;
        $systemUser = $this->deployment->system_user;
        $client = $this->createSshClient();

        try {
            $dirCheck = $client->exec(
                'sudo bash -c ' . escapeshellarg('[ -d ' . $providerDir . ' ] && echo EXISTS || echo MISSING')
            );

            if (str_contains($dirCheck, 'EXISTS')) {
                $result = $client->exec(
                    'sudo bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose down --rmi local 2>&1')
                    . '; echo "EXIT_CODE:$?"',
                    900,
                );

                if (! str_contains($result, 'EXIT_CODE:0')) {
                    throw new ValidationException(
                        ucfirst($config->providerName) . ' compose down failed: ' . $result
                    );
                }
            }
            // If the dir is missing the containers can't be running off it — fall through to
            // userdel + status update. The deployment row gets marked terminated regardless,
            // so this stays the canonical "make this go away" entry point.

            $client->exec('sudo userdel -r ' . escapeshellarg($systemUser) . ' 2>/dev/null || true', 60);

            $this->deployment->status = DeploymentStatusEnum::TERMINATED->value;
            $this->deployment->terminated_at = new Carbon();
            $this->deployment->saveOrFail();

            $this->deployment->agent->update(['deployment_status' => 'pending']);
        } finally {
            $client->disconnect();
        }

        return true;
    }
}
