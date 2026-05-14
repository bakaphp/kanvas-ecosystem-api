<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\AgentRuntime\Services\DockerComposeBuilder;
use Kanvas\Connectors\AgentRuntime\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Force-rebuild the shared OpenClaw Docker image on a machine.
 * Use when upgrading the base image or changing the Dockerfile/entrypoint.
 */
class RebuildSharedImageAction
{
    public function __construct(
        protected AgentMachine $machine,
        protected AppInterface $app,
    ) {
    }

    public function execute(): void
    {
        $builder   = new DockerComposeBuilder();
        $client    = SshClient::fromMachine($this->machine);
        $imageName = $builder->getSharedImageName($this->app);
        $imageDir  = $builder->getSharedImageDir($this->app);

        try {
            $client->exec('sudo mkdir -p ' . escapeshellarg($imageDir));

            $client->writeFileAsUser(
                $imageDir . '/Dockerfile',
                $builder->buildDockerfile($this->app),
                'root',
            );

            $client->writeFileAsUser(
                $imageDir . '/entrypoint.sh',
                $builder->buildEntrypoint(),
                'root',
            );
            $client->exec('sudo chmod +x ' . escapeshellarg($imageDir . '/entrypoint.sh'));

            $result = $client->exec(
                'cd ' . escapeshellarg($imageDir)
                . ' && sudo docker build --no-cache -t ' . escapeshellarg($imageName) . ' . 2>&1'
                . '; echo "EXIT_CODE:$?"',
                900,
            );

            if (! str_contains($result, 'EXIT_CODE:0')) {
                throw new ValidationException('Failed to rebuild shared OpenClaw image: ' . $result);
            }
        } finally {
            $client->disconnect();
        }
    }
}
