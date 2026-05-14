<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Illuminate\Support\Facades\Storage;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Notifications\AgentBackupNotification;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Users\Models\Users;
use Throwable;

// Subclasses' runBackupCli() runs the runtime's `<runtime> backup` CLI inside the container
// and returns the resulting archive filename (just the basename, written under /tmp on the
// container's filesystem). Everything downstream — docker cp, SFTP, S3 upload — is shared.
abstract class BaseBackupAgentWorkspaceAction
{
    public function __construct(
        protected AgentDeployment $deployment,
        protected AgentBackup $backup,
        protected bool $includeWorkspace = true,
    ) {
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    /**
     * Run the provider's backup CLI inside `$containerName` (writing the archive to /tmp
     * on the container's filesystem) and return the resulting archive filename WITHOUT
     * any directory prefix. Throws ValidationException on failure.
     */
    abstract protected function runBackupCli(SshClient $client, string $containerName): string;

    public function execute(): AgentBackup
    {
        $this->backup->status = 'running';
        $this->backup->saveOrFail();

        $client = $this->createSshClient($this->deployment->machine);
        $localTempFile = null;

        try {
            $providerConfig = $client::makeProviderConfig();
            $archiveName = $this->runBackupCli($client, $this->deployment->container_name);

            $localTempFile = $this->extractArchive($client, $this->deployment->container_name, $archiveName);
            $s3Path = $this->uploadToS3($localTempFile, $providerConfig->providerName);

            $size = @filesize($localTempFile);
            $this->backup->status = 'completed';
            $this->backup->file_path = $s3Path;
            $this->backup->file_size_bytes = $size === false ? null : $size;
            $this->backup->completed_at = now();
            $this->backup->saveOrFail();

            $this->notifyOwner(success: true);
        } catch (Throwable $e) {
            $this->backup->status = 'failed';
            $this->backup->error_message = $e->getMessage();
            $this->backup->saveOrFail();

            $this->notifyOwner(success: false, error: $e);

            throw $e;
        } finally {
            $client->disconnect();

            if ($localTempFile !== null && file_exists($localTempFile)) {
                unlink($localTempFile);
            }
        }

        return $this->backup;
    }

    /**
     * `docker cp` the archive from the container's /tmp to the host's /tmp, then SFTP it
     * down to a PHP temp file. Cleans up both remote copies in `finally`.
     */
    private function extractArchive(SshClient $client, string $containerName, string $archiveName): string
    {
        $containerPath = '/tmp/' . $archiveName;
        $hostPath = '/tmp/' . $archiveName;

        $cpResult = $client->exec(
            'sudo docker cp ' . escapeshellarg($containerName . ':' . $containerPath)
            . ' ' . escapeshellarg($hostPath) . ' 2>&1; echo "EXIT_CODE:$?"',
            60
        );

        if (str_contains($cpResult, 'EXIT_CODE:1')) {
            throw new ValidationException('Failed to copy backup archive from container: ' . $cpResult);
        }

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

    private function uploadToS3(string $localTempFile, string $providerName): string
    {
        $agentSlug = (string) ($this->deployment->agent->slug ?? $this->deployment->container_name);
        $filename = basename($localTempFile);
        $s3Path = $providerName . '-backups/' . $agentSlug . '/' . $filename;

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

    private function notifyOwner(bool $success, ?Throwable $error = null): void
    {
        $recipient = $this->deployment->agent?->user;
        if (! $recipient instanceof Users) {
            return;
        }

        $recipient->notify(new AgentBackupNotification($this->backup, $this->deployment, $success, $error));
    }
}
