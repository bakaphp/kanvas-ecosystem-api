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
 * Hermes concrete — invokes `hermes backup --quick -o /tmp/<name>.zip` inside the container.
 *
 * Per docs (`hermes backup [--quick] [-o PATH]`): `-o` takes the FULL archive path (not just
 * a directory), so we pre-compute a timestamped filename and tell the CLI to write there.
 * That's a small win over OpenClaw's "let the CLI pick a name and parse it out of stdout"
 * dance — no regex needed, no failure mode if the CLI's naming convention changes.
 *
 * `--quick` is used when the caller opts OUT of the workspace (state-only backup, what
 * Hermes calls "quick"). When the workspace IS included we drop the flag for a full backup.
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
