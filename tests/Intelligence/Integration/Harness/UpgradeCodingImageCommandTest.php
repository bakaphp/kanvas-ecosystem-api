<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Services\CodingImageService;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesAgentMachines;
use Tests\TestCase;

/**
 * Only the dry run is exercised end to end: a real run writes the image tag to the app's settings,
 * which live in Redis and never roll back. The replacement itself is covered by
 * `ReplaceStaleCodingContainersTest`.
 */
class UpgradeCodingImageCommandTest extends TestCase
{
    use CreatesAgentMachines;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testItRefusesWithoutAnApp(): void
    {
        $this->artisan('kanvas:coding:upgrade-image')->assertFailed();
    }

    public function testItRefusesWithoutAMachine(): void
    {
        $this->artisan('kanvas:coding:upgrade-image', ['--app' => app(Apps::class)->getId()])
            ->expectsOutputToContain('No machine to upgrade')
            ->assertFailed();
    }

    /**
     * The target defaults to what the Dockerfile builds, never to the app's current setting — that is
     * the version being left behind.
     */
    public function testADryRunTargetsTheTagTheDockerfileBuilds(): void
    {
        $machine = $this->createAgentMachine();

        $this->artisan('kanvas:coding:upgrade-image', [
            '--app' => app(Apps::class)->getId(),
            '--machine' => $machine->getId(),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Target image: ' . CodingImageService::pinnedTag())
            ->expectsOutputToContain('Would replace 0 container(s)')
            ->assertSuccessful();
    }
}
