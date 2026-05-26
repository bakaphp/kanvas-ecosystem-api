<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseFetchDailyLearningContextAction;
use Kanvas\Intelligence\AgentRuntime\SshClient as BaseSshClient;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Override;

// Reads only the Kanvas-owned file — OpenClaw's own `YYYY-MM-DD.md` files
// belong to `memory promote --apply` and aren't shaped for one-line dedup.
class FetchDailyLearningContextAction extends BaseFetchDailyLearningContextAction
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
}
