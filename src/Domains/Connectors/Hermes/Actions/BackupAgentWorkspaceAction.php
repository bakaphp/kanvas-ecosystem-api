<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseBackupAgentWorkspaceAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

/**
 * `hermes backup` accepts `-o /full/path.zip` — unlike OpenClaw which takes `--output /dir`
 * and picks the filename itself. We use the full-path form so we know the archive name
 * up front without parsing CLI output. `--quick` is documented as "state only, skip workspace".
 */
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
        $archiveName = sprintf(
            'hermes_backup_%s_%s.zip',
            $this->includeWorkspace ? 'full' : 'quick',
            date('Ymd_His'),
        );
        $containerArchivePath = '/tmp/' . $archiveName;
        $quickFlag = $this->includeWorkspace ? '' : ' --quick';

        $result = $client->exec(
            'sudo docker exec ' . escapeshellarg($containerName)
            . ' sh -c ' . escapeshellarg('hermes backup' . $quickFlag . ' -o ' . escapeshellarg($containerArchivePath) . ' 2>&1')
            . '; echo "EXIT_CODE:$?"',
            1800
        );

        if (! str_contains($result, 'EXIT_CODE:0')) {
            throw new ValidationException('hermes backup failed: ' . $result);
        }

        return $archiveName;
    }
}
