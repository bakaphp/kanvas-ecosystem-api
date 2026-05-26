<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BasePushDailyLearningContextAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;
use Throwable;

// Writes a Kanvas-owned file alongside OpenClaw's own `YYYY-MM-DD.md` —
// those belong to `memory promote --apply`; ours is a separate namespace.
// Reindex after write because OpenClaw consults the chunk/vector/FTS index,
// not the raw .md (unlike Hermes).
class PushDailyLearningContextAction extends BasePushDailyLearningContextAction
{
    #[Override]
    protected function createSshClient(AgentMachine $machine): BaseSshClient
    {
        return SshClient::fromMachine($machine);
    }

    #[Override]
    protected function resolveMemoryPath(): string
    {
        $home = $this->deployment->home_directory !== ''
            ? $this->deployment->home_directory
            : '/home/' . $this->deployment->system_user;

        return rtrim($home, '/') . '/.openclaw/workspace/memory/KANVAS-LEARNINGS.md';
    }

    #[Override]
    protected function runtimeName(): string
    {
        return 'OpenClaw';
    }

    // `--force` to bypass openclaw's dirty-file tracking; we just wrote a
    // file it doesn't know about. Returns false (not throws) on failure —
    // the write already landed, the index will catch up on the next run.
    #[Override]
    protected function afterWrite(BaseSshClient $ssh): bool
    {
        $container = $this->deployment->container_name;
        if ($container === '') {
            return false;
        }

        try {
            $cmd = 'docker exec ' . escapeshellarg($container)
                . ' timeout 60 openclaw memory index --force 2>&1';
            $output = $ssh->exec($cmd, 90);

            // No exit code via SSH exec; treat presence of "Error:"/"Failed"
            // in output as the failure signal.
            if (str_contains($output, 'Error:') || str_contains($output, 'Failed')) {
                Log::warning('OpenClaw memory index reported errors', [
                    'deployment_id' => $this->deployment->getId(),
                    'output' => substr($output, 0, 500),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('OpenClaw memory index failed', [
                'deployment_id' => $this->deployment->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
