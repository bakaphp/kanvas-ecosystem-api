<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\CodingWorkspaceService;
use Mockery;
use Tests\TestCase;

/**
 * Every path on a machine is built on this root, so a trailing slash or a blank setting here would
 * put `//` or `/agents` into every mirror, worktree and binary path at once.
 */
class CodingWorkspaceServiceTest extends TestCase
{
    public function testTheDefaultRootIsUsedWhenNothingIsConfigured(): void
    {
        $this->assertSame('/srv/kanvas', CodingWorkspaceService::rootFor($this->app(null)));
    }

    public function testABlankSettingIsTheDefault(): void
    {
        $this->assertSame('/srv/kanvas', CodingWorkspaceService::rootFor($this->app('   ')));
    }

    public function testATrailingSlashIsDropped(): void
    {
        $this->assertSame('/Users/kanvasrun/srv', CodingWorkspaceService::rootFor($this->app('/Users/kanvasrun/srv/')));
    }

    private function app(?string $root): Apps
    {
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('get')
            ->with(ConfigurationEnum::WORKSPACE_ROOT->value)
            ->andReturn($root);

        return $app;
    }
}
