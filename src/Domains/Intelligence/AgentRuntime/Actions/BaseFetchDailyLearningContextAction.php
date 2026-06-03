<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use RuntimeException;
use Throwable;

abstract class BaseFetchDailyLearningContextAction
{
    public function __construct(
        protected readonly AgentDeployment $deployment,
    ) {
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    abstract protected function resolveMemoryPath(): string;

    abstract protected function runtimeName(): string;

    public function execute(): string
    {
        $machine = $this->deployment->machine;
        if ($machine === null) {
            Log::warning(sprintf('%s daily-learning fetch skipped: deployment has no machine', $this->runtimeName()), [
                'deployment_id' => $this->deployment->getId(),
            ]);

            return '';
        }

        $ssh = $this->createSshClient($machine);

        try {
            return $this->safeReadFile($ssh, $this->resolveMemoryPath());
        } catch (Throwable $e) {
            Log::warning(sprintf('%s daily-learning fetch failed', $this->runtimeName()), [
                'deployment_id' => $this->deployment->getId(),
                'error' => $e->getMessage(),
            ]);

            return '';
        } finally {
            $ssh->disconnect();
        }
    }

    // Missing file is the first-push case — empty rather than throw. Other
    // SSH errors propagate to the catch in execute().
    protected function safeReadFile(SshClient $ssh, string $path): string
    {
        try {
            return $ssh->readFileAsUser($path);
        } catch (RuntimeException) {
            return '';
        }
    }
}
