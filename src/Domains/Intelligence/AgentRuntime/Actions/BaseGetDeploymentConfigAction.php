<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Read the deployment's runtime config file (openclaw.json / config.yaml / ...) over SFTP.
 *
 * Returns the raw file contents — callers are responsible for decoding. The path is composed
 * from ProviderConfig's `dotDir` + `configFilename`, so each runtime points at the right file.
 */
abstract class BaseGetDeploymentConfigAction
{
    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    public function execute(): string
    {
        if (! $this->deployment->isRunning()) {
            throw new ValidationException('Cannot read config from a deployment that is not running');
        }

        $client = $this->createSshClient($this->deployment->machine);

        try {
            $providerConfig = $client::makeProviderConfig();
            $configPath = $this->deployment->home_directory . '/.' . $providerConfig->dotDir . '/' . $providerConfig->configFilename;

            return trim($client->readFile($configPath));
        } finally {
            $client->disconnect();
        }
    }
}
