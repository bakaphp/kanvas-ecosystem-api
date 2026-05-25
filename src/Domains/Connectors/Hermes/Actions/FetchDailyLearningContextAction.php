<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Hermes\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use RuntimeException;
use Throwable;

/**
 * Read the agent's current MEMORY.md so the summarize prompt can inject it
 * as "facts already known — don't re-emit." Symmetric counterpart to
 * PushDailyLearningContextAction; same path resolution, opposite direction.
 *
 * Returns '' (not throws) when the file is missing or unreadable — empty
 * string is the documented "nothing to share" answer per the runtime
 * contract, so the caller doesn't need a try/catch.
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
            Log::warning('Hermes daily-learning fetch skipped: deployment has no machine', [
                'deployment_id' => $this->deployment->getId(),
            ]);
            return '';
        }

        $ssh = SshClient::fromMachine($machine);

        try {
            return $this->safeReadFile($ssh, $this->resolveMemoryPath());
        } catch (Throwable $e) {
            Log::warning('Hermes daily-learning fetch failed', [
                'deployment_id' => $this->deployment->getId(),
                'error' => $e->getMessage(),
            ]);
            return '';
        } finally {
            $ssh->disconnect();
        }
    }

    private function resolveMemoryPath(): string
    {
        $home = $this->deployment->home_directory !== ''
            ? $this->deployment->home_directory
            : '/home/' . $this->deployment->system_user;

        return rtrim($home, '/') . '/.hermes/memories/MEMORY.md';
    }

    /**
     * Missing file is the first-push case — return empty rather than throw.
     * Other SSH errors propagate to the catch in execute().
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
