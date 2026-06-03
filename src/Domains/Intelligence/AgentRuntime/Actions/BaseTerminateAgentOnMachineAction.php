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
        $homeDir = $this->deployment->home_directory;
        $gatewayPort = $this->deployment->gateway_port;
        $proxyPort = $this->deployment->proxy_port;
        $client = $this->createSshClient();

        try {
            $dirCheck = $client->exec(
                'sudo bash -c ' . escapeshellarg('[ -d ' . $providerDir . ' ] && echo EXISTS || echo MISSING')
            );

            if (str_contains($dirCheck, 'EXISTS')) {
                $result = $client->exec(
                    'sudo bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose down --rmi local --remove-orphans 2>&1')
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
            // port cleanup + userdel + status update. The deployment row gets marked terminated
            // regardless, so this stays the canonical "make this go away" entry point.

            // Force-remove any containers still bound to the deployment ports — catches stray
            // containers that survived compose down or were started outside compose.
            $client->exec(
                'docker ps -q --filter publish=' . $gatewayPort . ' | xargs -r docker rm -f 2>/dev/null || true'
                . ' ; docker ps -q --filter publish=' . $proxyPort . ' | xargs -r docker rm -f 2>/dev/null || true',
                30
            );

            // Prune dangling images left by --rmi local (intermediate build layers, etc.).
            // Safe: only removes untagged images not referenced by any container.
            $client->exec('docker image prune -f 2>/dev/null || true', 60);

            $client->exec('sudo userdel -r ' . escapeshellarg($systemUser) . ' 2>/dev/null || true', 60);

            // userdel -r silently fails to remove files owned by root (e.g. written by docker
            // containers that ran as root inside the bind-mounted volume). Remove explicitly.
            $client->exec('sudo rm -rf ' . escapeshellarg($homeDir) . ' 2>/dev/null || true', 60);

            $this->deployment->status = DeploymentStatusEnum::TERMINATED->value;
            $this->deployment->terminated_at = new Carbon();
            $this->deployment->saveOrFail();

            $this->deployment->agent?->update(['deployment_status' => 'pending']);

            $this->afterTerminate($client);
        } finally {
            $client->disconnect();
        }

        return true;
    }

    /**
     * Hook for provider-specific cleanup after containers and user are removed.
     * Called after status is persisted — safe for DB writes and non-critical work.
     */
    protected function afterTerminate(SshClient $client): void
    {
    }
}
