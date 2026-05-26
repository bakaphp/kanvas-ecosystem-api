<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use RuntimeException;
use Throwable;

/**
 * Read Kanvas's namespaced durable-facts file so the summarize prompt can
 * inject it as "facts already known — don't re-emit." We deliberately don't
 * read OpenClaw's own per-day `workspace/memory/YYYY-MM-DD.md` files — those
 * are owned by OpenClaw's `memory promote --apply` pipeline and not shaped
 * for one-line dedup.
 *
 * Returns '' (not throws) when the file is missing or unreadable.
 */
class FetchDailyLearningContextAction
{
    public function __construct(
        protected readonly AgentDeployment $deployment,
    ) {
    }

    public function execute(): string
    {
        $machine = $this->deployment->machine;
        if ($machine === null) {
            Log::warning('OpenClaw daily-learning fetch skipped: deployment has no machine', [
                'deployment_id' => $this->deployment->getId(),
            ]);

            return '';
        }

        $ssh = SshClient::fromMachine($machine);

        try {
            return $this->safeReadFile($ssh, $this->resolveMemoryPath());
        } catch (Throwable $e) {
            Log::warning('OpenClaw daily-learning fetch failed', [
                'deployment_id' => $this->deployment->getId(),
                'error' => $e->getMessage(),
            ]);

            return '';
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * `~/.openclaw/workspace/memory/KANVAS-LEARNINGS.md`. The dir is 0700
     * owned by the container's agent UID — sudo cat (readFileAsUser) is
     * mandatory; SFTP read would silently return ''.
     */
    private function resolveMemoryPath(): string
    {
        $home = $this->deployment->home_directory !== ''
            ? $this->deployment->home_directory
            : '/home/' . $this->deployment->system_user;

        return rtrim($home, '/') . '/.openclaw/workspace/memory/KANVAS-LEARNINGS.md';
    }

    /**
     * Missing file is the first-push case — empty rather than throw. Other
     * SSH errors propagate to the catch in execute().
     */
    private function safeReadFile(SshClient $ssh, string $path): string
    {
        try {
            return $ssh->readFileAsUser($path);
        } catch (RuntimeException) {
            return '';
        }
    }
}
