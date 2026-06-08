<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Bus;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Jobs\SyncDeploymentKanbanJob;
use Kanvas\NervousSystem\Plan\Listeners\SyncKanbanAfterChatListener;
use Mockery;
use Tests\TestCase;

/**
 * The post-chat snappy-ingest hook: a chat turn with a running Hermes agent kicks a one-off board
 * sync; non-Hermes / non-running agents are left to the cron.
 */
final class SyncKanbanAfterChatListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testRunningHermesAgentTriggersSync(): void
    {
        Bus::fake();

        new SyncKanbanAfterChatListener()->handle($this->chatEvent(provider: 'hermes', running: true));

        Bus::assertDispatched(SyncDeploymentKanbanJob::class);
    }

    public function testNonHermesAgentDoesNotSync(): void
    {
        Bus::fake();

        new SyncKanbanAfterChatListener()->handle($this->chatEvent(provider: 'openclaw', running: true));

        Bus::assertNotDispatched(SyncDeploymentKanbanJob::class);
    }

    public function testStoppedDeploymentDoesNotSync(): void
    {
        Bus::fake();

        new SyncKanbanAfterChatListener()->handle($this->chatEvent(provider: 'hermes', running: false));

        Bus::assertNotDispatched(SyncDeploymentKanbanJob::class);
    }

    private function chatEvent(string $provider, bool $running): AgentChatResponseEvent
    {
        $deployment = Mockery::mock(AgentDeployment::class)->makePartial();
        $deployment->shouldReceive('isRunning')->andReturn($running);
        $deployment->shouldReceive('getAttribute')->with('provider')->andReturn($provider);

        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('getAttribute')->with('activeDeployment')->andReturn($deployment);

        return new AgentChatResponseEvent($agent, 'session-uuid', 'hi', 'hello back');
    }
}
