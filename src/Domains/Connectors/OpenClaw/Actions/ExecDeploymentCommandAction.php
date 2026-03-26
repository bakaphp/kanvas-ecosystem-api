<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\Jobs\ExecDeploymentCommandJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class ExecDeploymentCommandAction
{
    public function __construct(
        protected AgentDeployment $deployment,
        protected string $command,
        protected string $sessionId,
    ) {
    }

    public function execute(): bool
    {
        if (! $this->deployment->isRunning()) {
            throw new ValidationException('Cannot execute commands on a deployment that is not running');
        }

        $sanitized = $this->sanitizeCommand($this->command);

        if ($sanitized === '') {
            throw new ValidationException('Command cannot be empty');
        }

        ExecDeploymentCommandJob::dispatch(
            $this->deployment,
            $sanitized,
            $this->sessionId,
        );

        return true;
    }

    protected function sanitizeCommand(string $command): string
    {
        // Strip shell metacharacters — only OpenClaw CLI subcommands are allowed
        $sanitized = preg_replace('/[;&|`$><()\\\]/', '', $command);

        return trim((string) $sanitized);
    }
}
