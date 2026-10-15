<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\OpenCode\Actions\EnsureAgentCodingContainerAction;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use ReflectionMethod;
use Tests\Intelligence\Integration\Harness\Concerns\MocksCodingApp;
use Tests\Intelligence\Integration\Harness\Fakes\FakeCodingSshClient;
use Tests\TestCase;

/**
 * An agent's container is reused across tasks, so an image upgrade only takes effect if the reuse
 * check refuses a container on the old image.
 */
class ContainerImageReuseTest extends TestCase
{
    use DatabaseTransactions;
    use MocksCodingApp;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string CONTAINER = 'kanvas-coding-agent-1';
    private const string IMAGE = 'kanvas/opencode:2.0.17';

    public function testARunningContainerOnTheConfiguredImageWithTheCurrentKeyIsReused(): void
    {
        $action = $this->action();

        $this->assertTrue($this->reusable($action, self::CONTAINER . '|' . $this->fingerprint($action) . '|' . self::IMAGE));
    }

    public function testAContainerOnAnOlderImageIsNotReused(): void
    {
        $action = $this->action();

        $this->assertFalse($this->reusable($action, self::CONTAINER . '|' . $this->fingerprint($action) . '|kanvas/opencode:2.0.16'));
    }

    /**
     * Docker shows the bare image id once the tag has been rebuilt under the container.
     */
    public function testAContainerOnARebuiltTagIsNotReused(): void
    {
        $action = $this->action();

        $this->assertFalse($this->reusable($action, self::CONTAINER . '|' . $this->fingerprint($action) . '|sha256:9c1d'));
    }

    public function testAContainerStartedWithAnOlderKeyIsNotReused(): void
    {
        $this->assertFalse($this->reusable($this->action(), self::CONTAINER . '|0000000000000000|' . self::IMAGE));
    }

    public function testNoRunningContainerIsNotReused(): void
    {
        $this->assertFalse($this->reusable($this->action(), ''));
    }

    private function reusable(EnsureAgentCodingContainerAction $action, string $listing): bool
    {
        return new ReflectionMethod($action, 'isRunningWithCurrentKeyAndImage')->invoke(
            $action,
            new FakeCodingSshClient(['docker ps' => $listing]),
            self::CONTAINER,
            self::IMAGE
        );
    }

    private function fingerprint(EnsureAgentCodingContainerAction $action): string
    {
        return new ReflectionMethod($action, 'keyFingerprint')->invoke($action);
    }

    private function action(): EnsureAgentCodingContainerAction
    {
        $agent = $this->agent([]);

        return new EnsureAgentCodingContainerAction($agent, new AgentMachine(), $agent->app);
    }
}
