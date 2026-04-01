<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class GetDeploymentConfigAction
{
    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    public function execute(): string
    {
        if (! $this->deployment->isRunning()) {
            throw new ValidationException('Cannot read config from a deployment that is not running');
        }

        $client = SshClient::fromMachine($this->deployment->machine);

        try {
            $configPath = $this->deployment->home_directory . '/.openclaw/openclaw.json';

            return trim($client->readFile($configPath));
        } finally {
            $client->disconnect();
        }
    }
}
