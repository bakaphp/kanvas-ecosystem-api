<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Intelligence\Agents\Neuron\Coding\SelfHostedProgrammingAgent;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesHarnessAgents;
use Tests\TestCase;

/**
 * The chat-side agent decides whether a job is dispatched at all. Told only about a Linux container,
 * it answered a request to run an iOS app in the Simulator with "I have no emulator" without checking
 * the machine, so the prompt has to name the host runtime and the run-only path.
 */
class SelfHostedProgrammingAgentInstructionsTest extends TestCase
{
    use CreatesHarnessAgents;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testTheAgentIsToldToCheckTheMachineBeforeDecliningABuildOrASimulator(): void
    {
        [$app, $company, $user] = $this->context();
        $handler = new SelfHostedProgrammingAgent();
        $handler->setConfiguration($this->makeAgent($app, $company, $user), user: $user);

        $instructions = $handler->instructions();

        $this->assertStringContainsString('machine.host_tools', $instructions);
        $this->assertStringContainsString('iOS Simulator', $instructions);
        $this->assertStringContainsString('expects_changes set to false', $instructions);
        $this->assertStringNotContainsString('isolated container', $instructions);
    }
}
