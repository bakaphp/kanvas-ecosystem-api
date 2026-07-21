<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseBackupAgentWorkspaceAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

class BackupAgentWorkspaceAction extends BaseBackupAgentWorkspaceAction
{
    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }

    #[Override]
    protected function runBackupCli(BaseSshClient $client, string $containerName): string
    {
        $backupFlags = $this->includeWorkspace ? '' : ' --no-include-workspace';

        // Invoke the CLI as `node /app/openclaw.mjs` (the provider's mjsPath), not a bare
        // `openclaw`. The container's `openclaw` on PATH isn't reliably executable — on some
        // base images it's a non-exec file, so `sh -c 'openclaw ...'` dies with
        // "Permission denied" (exit 127). Every other working action uses mjsPath; the entrypoint
        // itself runs `node /app/openclaw.mjs`, so this is the canonical, always-executable path.
        $mjsPath = $client::makeProviderConfig()->mjsPath;

        $result = $client->exec(
            'sudo docker exec ' . escapeshellarg($containerName)
            . ' sh -c ' . escapeshellarg($mjsPath . ' backup create --output /tmp' . $backupFlags . ' 2>&1')
            . '; echo "EXIT_CODE:$?"',
            1800
        );

        if (str_contains($result, 'EXIT_CODE:1') || ! str_contains($result, 'EXIT_CODE:0')) {
            throw new ValidationException('openclaw backup create failed: ' . $result);
        }

        if (! preg_match('/[\w\-:.]+\.tar\.gz/', $result, $matches)) {
            throw new ValidationException('Could not parse backup archive filename from output: ' . $result);
        }

        return basename($matches[0]);
    }
}
