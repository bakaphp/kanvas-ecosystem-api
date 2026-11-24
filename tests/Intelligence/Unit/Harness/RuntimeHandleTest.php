<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\RuntimeHandle;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineRuntimeEnum;
use Tests\TestCase;

/**
 * A container sees the worktree root mounted at `/workspaces`, so a session's directory has to be
 * translated for it; a host process works in the path as it is.
 */
class RuntimeHandleTest extends TestCase
{
    public function testSessionDirectoryIsTheMountedPathInAContainerAndTheHostPathOnAHost(): void
    {
        $workspace = '/srv/kanvas/agents/1/worktrees/abc';

        $this->assertSame('/workspaces/abc', $this->handleFor(MachineRuntimeEnum::CONTAINER)->sessionDirectory($workspace));
        $this->assertSame($workspace, $this->handleFor(MachineRuntimeEnum::HOST)->sessionDirectory($workspace));
    }

    private function handleFor(MachineRuntimeEnum $runtime): RuntimeHandle
    {
        return new RuntimeHandle(
            runtime: $runtime,
            handle: 'c',
            endpoint: 'http://127.0.0.1:4096',
            port: null,
            password: 'pw',
            worktreeRoot: '/srv/kanvas/agents/1/worktrees',
        );
    }
}
