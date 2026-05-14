<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

abstract class BaseExecDeploymentCommandAction
{
    public function __construct(
        protected AgentDeployment $deployment,
        protected string $command,
        protected string $sessionId,
    ) {
    }

    /**
     * Dispatch the provider's `*ExecDeploymentCommandJob` with the already-sanitized command.
     */
    abstract protected function dispatchExecJob(string $sanitizedCommand): void;

    public function execute(): bool
    {
        if (! $this->deployment->isRunning()) {
            throw new ValidationException('Cannot execute commands on a deployment that is not running');
        }

        $sanitized = $this->sanitizeCommand($this->command);

        if ($sanitized === '') {
            throw new ValidationException('Command cannot be empty');
        }

        $this->dispatchExecJob($sanitized);

        return true;
    }

    /**
     * Strip shell metacharacters — only the runtime's own CLI subcommands are allowed.
     * Whitelist is intentionally permissive on word chars/spaces/dashes/slashes/dots so
     * normal CLI invocations like `status --usage --json` survive intact.
     */
    protected function sanitizeCommand(string $command): string
    {
        $sanitized = preg_replace('/[;&|`$><()\\\]/', '', $command);

        return trim((string) $sanitized);
    }
}
