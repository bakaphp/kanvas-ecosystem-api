<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Intelligence\Agents\Neuron\Tools\Harness\FetchHarnessCodingArtifactTool;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * What the artifact tool will and will not read off a coding machine.
 *
 * Covered without the tool's dependencies because this is the part an attacker aims at: the container
 * mounts the agent's WHOLE worktree root, so a path that climbs out of one session's checkout reaches
 * every sibling task and `.home` — opencode's session store, which on this setup sits beside the
 * credentials its container was started with. Everything else the tool does is recoverable; this is not.
 */
class ArtifactPathGuardTest extends TestCase
{
    private const string WORKSPACE = '/srv/kanvas/agents/42/worktrees/01a0d95c';

    public static function escapes(): array
    {
        return [
            'parent' => ['../other-session/.env'],
            'deep climb to the opencode store' => ['../../.home/opencode/auth.json'],
            'climb then descend' => ['reports/../../../.home/auth.json'],
            'absolute path' => ['/etc/passwd'],
            'absolute path inside the mount' => ['/srv/kanvas/agents/42/worktrees/.home/auth.json'],
            'backslash separators' => ['..\\..\\.home\\auth.json'],
            'bare dot-dot' => ['..'],
            'empty' => ['   '],
            'only slashes' => ['///'],
        ];
    }

    #[DataProvider('escapes')]
    public function testRefusesAnythingThatLeavesTheSessionsOwnCheckout(string $path): void
    {
        $this->assertNull($this->resolve($path));
    }

    public static function allowed(): array
    {
        return [
            'a file at the root' => ['report.pdf', '/report.pdf'],
            'nested' => ['reports/q3/revenue.csv', '/reports/q3/revenue.csv'],
            'redundant current-dir segments' => ['./reports/./chart.png', '/reports/chart.png'],
            'double slashes collapse' => ['reports//chart.png', '/reports/chart.png'],
        ];
    }

    #[DataProvider('allowed')]
    public function testResolvesOrdinaryPathsUnderTheWorkspace(string $path, string $expectedSuffix): void
    {
        $this->assertSame(self::WORKSPACE . $expectedSuffix, $this->resolve($path));
    }

    private function resolve(string $path): ?string
    {
        $method = new ReflectionMethod(FetchHarnessCodingArtifactTool::class, 'resolveInsideWorkspace');

        return $method->invoke(
            $this->toolWithoutConstructor(),
            self::WORKSPACE,
            $path,
        );
    }

    /**
     * The guard is pure string work; building the tool properly would drag in an Agent, a machine and
     * a tenant for no added coverage.
     */
    private function toolWithoutConstructor(): FetchHarnessCodingArtifactTool
    {
        return new ReflectionClass(FetchHarnessCodingArtifactTool::class)->newInstanceWithoutConstructor();
    }
}
