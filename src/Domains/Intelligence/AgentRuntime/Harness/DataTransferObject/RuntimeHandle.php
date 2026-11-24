<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject;

use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;

/**
 * What a driver hands back from `ensure()`: enough for the session row and nothing the runtime
 * keeps to itself. `handle` is what `agent_task_sessions.container_name` stores — a container name
 * or a launchd label.
 */
final readonly class RuntimeHandle
{
    public function __construct(
        public MachineRuntimeEnum $runtime,
        public string $handle,
        public string $endpoint,
        public ?int $port,
        public string $password,
        public string $worktreeRoot,
    ) {
    }

    /**
     * The workspace as opencode sees it: the container mounts the worktree root at `/workspaces`, a
     * host process sees the host path.
     */
    public function sessionDirectory(string $hostWorkspace): string
    {
        return match ($this->runtime) {
            MachineRuntimeEnum::CONTAINER => '/workspaces/' . basename($hostWorkspace),
            MachineRuntimeEnum::HOST => $hostWorkspace,
        };
    }
}
