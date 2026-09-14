<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\ClaudeAgent\Providers\ClaudeProvider;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use LogicException;
use Tests\TestCase;

final class ClaudeProviderTest extends TestCase
{
    use DatabaseTransactions;

    /** Settings live on mysql; agents, types and sessions on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    public function testFactoryResolvesTheProvider(): void
    {
        $provider = AgentRuntimeProviderFactory::forProvider(AgentProviderEnum::CLAUDE);

        $this->assertInstanceOf(ClaudeProvider::class, $provider);
        $this->assertSame(AgentProviderEnum::CLAUDE, $provider->name());
    }

    /**
     * isRuntimeProvider drives Agent::isContainerRuntime(), which is what routes a chat turn to
     * RunRuntimeChatAction instead of an in-process handler. Without this the whole provider is
     * unreachable from the chat path.
     */
    public function testClaudeCountsAsARemoteRuntime(): void
    {
        $this->assertTrue(AgentProviderEnum::CLAUDE->isRuntimeProvider());
        $this->assertTrue(AgentProviderEnum::CLAUDE->isHosted());
        $this->assertTrue(AgentProviderEnum::CLAUDE->isClaude());
    }

    /**
     * The regression this guards: AgentMachineMutation::updateContainers fans out over
     * runtimeProviders() calling dispatchUpdateMachineContainers(). A hosted runtime has no
     * machine and default-throws, so putting it in that list breaks the mutation for every tenant.
     */
    public function testClaudeIsNotAMachineRuntime(): void
    {
        $this->assertNotContains(AgentProviderEnum::CLAUDE, AgentProviderEnum::runtimeProviders());
        $this->assertContains(AgentProviderEnum::CLAUDE, AgentProviderEnum::hostedProviders());
        $this->assertContains(AgentProviderEnum::CLAUDE, AgentProviderEnum::remoteProviders());
    }

    public function testMachineRuntimesAreUnchanged(): void
    {
        $this->assertSame(
            [AgentProviderEnum::OPENCLAW, AgentProviderEnum::HERMES],
            AgentProviderEnum::runtimeProviders(),
        );
    }

    public function testHostedProviderIsNotTreatedAsInProcess(): void
    {
        $this->assertNotContains(AgentProviderEnum::CLAUDE, AgentProviderEnum::inProcessProviders());
    }

    /**
     * Machine ops keep the abstract base's default-throw rather than being stubbed with a fake
     * success — a caller reaching for them on a hosted runtime has a wrong assumption we want
     * surfaced, not swallowed.
     */
    public function testMachineOperationsStillThrow(): void
    {
        $this->expectException(LogicException::class);

        new ClaudeProvider()->dispatchRestart(new AgentDeployment());
    }
}
