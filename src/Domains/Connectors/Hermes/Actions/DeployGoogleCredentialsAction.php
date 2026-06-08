<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\Services\GoogleCredentialFileBuilderService;
use Kanvas\Connectors\Hermes\Traits\OpensHermesSshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Enums\AgentIntegrationConfigKeyEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class DeployGoogleCredentialsAction
{
    use OpensHermesSshClient;

    private const int CONTAINER_UID = 1000;
    private const int RESTART_TIMEOUT_SECONDS = 120;

    public function __construct(
        protected Agent $agent,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $config = $this->agent->get(AgentIntegrationConfigKeyEnum::GOOGLE->value);

        if (! is_array($config)) {
            throw new ValidationException(
                'Agent has no Google integration config to deploy'
            );
        }

        $deployment = $this->agent->activeDeployment;

        if (! $deployment instanceof AgentDeployment || ! $deployment->isRunning()) {
            return [
                'deployed' => false,
                'reason' => 'no_running_deployment',
                'agent_id' => $this->agent->getId(),
            ];
        }

        $files = GoogleCredentialFileBuilderService::buildFiles($config);

        $providerDir = rtrim($deployment->home_directory, '/') . '/.hermes';
        $client = $this->openSshClient($deployment->machine);

        try {
            $writtenFiles = [];

            foreach ($files as $filename => $contents) {
                $remotePath = $providerDir . '/' . $filename;

                $client->writeFileAsUser(
                    $remotePath,
                    $contents,
                    $deployment->system_user,
                );

                // node process inside the container runs as UID 1000; the
                // file must be readable by that uid regardless of the host
                // system_user that owns the agent directory on the machine.
                $client->exec(
                    'sudo chown ' . self::CONTAINER_UID . ':' . self::CONTAINER_UID
                    . ' ' . escapeshellarg($remotePath)
                );

                $writtenFiles[] = $remotePath;
            }

            $client->exec(
                'sudo -u ' . escapeshellarg($deployment->system_user)
                . ' bash -c ' . escapeshellarg('cd ' . $providerDir . ' && docker compose restart 2>&1'),
                self::RESTART_TIMEOUT_SECONDS,
            );
        } finally {
            $client->disconnect();
        }

        return [
            'deployed' => true,
            'agent_id' => $this->agent->getId(),
            'deployment_id' => $deployment->getId(),
            'files' => $writtenFiles,
        ];
    }
}
