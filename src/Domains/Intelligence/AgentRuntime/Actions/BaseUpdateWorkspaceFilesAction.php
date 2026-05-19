<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Kanvas\Intelligence\AgentRuntime\Contracts\ProviderConfig;
use Kanvas\Intelligence\AgentRuntime\Services\WorkspaceFileBuilder;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

abstract class BaseUpdateWorkspaceFilesAction
{
    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    abstract protected function getProviderConfig(): ProviderConfig;

    abstract protected function createSshClient(): SshClient;

    abstract protected function getDockerComposeBuilder(): mixed;

    public function execute(): void
    {
        $config = $this->getProviderConfig();
        $providerDir = $this->deployment->home_directory . '/.' . $config->dotDir;
        $systemUser = $this->deployment->system_user;
        $agent = $this->deployment->agent;
        $builder = $this->getDockerComposeBuilder();
        $client = $this->createSshClient();

        try {
            $files = WorkspaceFileBuilder::buildAll($agent);

            foreach ($files as $filename => $content) {
                $target = $builder->getWorkspaceFileTargetPath($providerDir, $filename);

                if ($target === null) {
                    continue;
                }

                $client->exec('sudo mkdir -p ' . escapeshellarg(dirname($target)));
                $client->writeFileAsUser($target, $content, $systemUser);
            }
        } finally {
            $client->disconnect();
        }
    }
}
