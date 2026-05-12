<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Actions;

use Illuminate\Support\Facades\Storage;
use Kanvas\Connectors\AgentRuntime\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

/**
 * Run `openclaw backup create` inside the running gateway container,
 * pull the resulting archive out via `docker cp`, download it to a local
 * temp file via SFTP, upload to S3, and update the AgentBackup record.
 *
 * The native CLI backup includes: config, auth-profiles, workspace files,
 * sessions, and credentials — everything needed to restore the agent later.
 */
class BackupAgentWorkspaceAction
{
    public function __construct(
        protected AgentDeployment $deployment,
        protected AgentBackup $backup,
        protected bool $includeWorkspace = true,
    ) {
    }

    public function execute(): AgentBackup
    {
        $this->backup->status = 'running';
        $this->backup->saveOrFail();

        $client = SshClient::fromMachine($this->deployment->machine);

        try {
            $localTempFile = $this->runBackupInContainer($client);

            $s3Path = $this->uploadToS3($localTempFile);

            $this->backup->status = 'completed';
            $this->backup->file_path = $s3Path;
            $this->backup->file_size_bytes = filesize($localTempFile) ?: null;
            $this->backup->completed_at = now();
            $this->backup->saveOrFail();
        } catch (Throwable $e) {
            $this->backup->status = 'failed';
            $this->backup->error_message = $e->getMessage();
            $this->backup->saveOrFail();

            throw $e;
        } finally {
            $client->disconnect();

            if (isset($localTempFile) && file_exists($localTempFile)) {
                unlink($localTempFile);
            }
        }

        return $this->backup;
    }

    /**
     * Run the native openclaw backup CLI inside the gateway container,
     * copy the archive to the host via `docker cp`, then download via SFTP.
     * Returns the local PHP temp file path.
     */
    private function runBackupInContainer(SshClient $client): string
    {
        $containerName = $this->deployment->container_name;
        $backupFlags = $this->includeWorkspace ? '' : ' --no-include-workspace';

        // Run backup inside the container, writing to /tmp
        $result = $client->exec(
            'sudo docker exec ' . escapeshellarg($containerName)
            . ' sh -c ' . escapeshellarg('openclaw backup create --output /tmp' . $backupFlags . ' 2>&1')
            . '; echo "EXIT_CODE:$?"',
            1800
        );

        if (str_contains($result, 'EXIT_CODE:1') || ! str_contains($result, 'EXIT_CODE:0')) {
            throw new ValidationException('openclaw backup create failed: ' . $result);
        }

        // Extract the timestamped archive filename from the CLI output
        preg_match('/[\w\-:.]+\.tar\.gz/', $result, $matches);
        if (empty($matches[0])) {
            throw new ValidationException('Could not parse backup archive filename from output: ' . $result);
        }

        $archiveName = basename($matches[0]);
        $containerPath = '/tmp/' . $archiveName;
        $hostPath = '/tmp/' . $archiveName;

        // Copy archive from container filesystem to host /tmp
        $cpResult = $client->exec(
            'sudo docker cp ' . escapeshellarg($containerName . ':' . $containerPath)
            . ' ' . escapeshellarg($hostPath) . ' 2>&1; echo "EXIT_CODE:$?"',
            60
        );

        if (str_contains($cpResult, 'EXIT_CODE:1')) {
            throw new ValidationException('Failed to copy backup archive from container: ' . $cpResult);
        }

        // Stream from host /tmp to PHP temp file via SFTP
        $localTempFile = sys_get_temp_dir() . '/' . $archiveName;

        try {
            if (! $client->downloadToFile($hostPath, $localTempFile)) {
                throw new ValidationException('Failed to download backup archive from machine');
            }
        } finally {
            $client->exec('sudo docker exec ' . escapeshellarg($containerName) . ' rm -f ' . escapeshellarg($containerPath));
            $client->exec('rm -f ' . escapeshellarg($hostPath));
        }

        return $localTempFile;
    }

    private function uploadToS3(string $localTempFile): string
    {
        $agentSlug = $this->deployment->agent->slug ?? $this->deployment->container_name;
        $filename = basename($localTempFile);
        $s3Path = 'openclaw-backups/' . $agentSlug . '/' . $filename;

        $stream = fopen($localTempFile, 'r');

        if ($stream === false) {
            throw new ValidationException('Failed to open backup archive for upload');
        }

        try {
            Storage::put($s3Path, $stream);
        } finally {
            fclose($stream);
        }

        return $s3Path;
    }
}
