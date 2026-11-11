<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Baka\Contracts\AppInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\OpenCode\Enums\MachineCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Services\CodingRuntimeReadinessService;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Neuron\Tools\Harness\CheckHarnessCodingSetupTool;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesAgentMachines;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesHarnessAgents;
use Tests\TestCase;

/**
 * The PM reads `machine` to decide what it may promise. A bare name told it nothing about whether the
 * box could build an iOS app, so it promised a Simulator on a Linux container and apologised for one
 * on the Mac.
 */
final class CheckHarnessCodingSetupToolTest extends TestCase
{
    use CreatesAgentMachines;
    use CreatesHarnessAgents;
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testTheMachineIsReportedWithRuntimeAndHostTools(): void
    {
        [$app, $company, $user] = $this->context();
        $machine = $this->createAgentMachine(runtime: 'host');
        $machine->set(MachineCustomFieldEnum::HOST_TOOLS->value, ['xcode']);

        $result = new CheckHarnessCodingSetupTool($this->makeAgent($app, $company, $user), $this->readiness($machine))->__invoke();

        $this->assertTrue($result['ready']);
        $this->assertSame(['name' => $machine->name, 'runtime' => 'host', 'host_tools' => ['xcode']], $result['machine']);
        $this->assertStringContainsString('host_tools', $result['note']);
    }

    public function testAContainerMachineAdvertisesNoTools(): void
    {
        [$app, $company, $user] = $this->context();
        $machine = $this->createAgentMachine();

        $result = new CheckHarnessCodingSetupTool($this->makeAgent($app, $company, $user), $this->readiness($machine))->__invoke();

        $this->assertSame(['name' => $machine->name, 'runtime' => 'container', 'host_tools' => []], $result['machine']);
    }

    public function testNoMachineIsNull(): void
    {
        [$app, $company, $user] = $this->context();

        $result = new CheckHarnessCodingSetupTool($this->makeAgent($app, $company, $user), $this->readiness(null))->__invoke();

        $this->assertFalse($result['ready']);
        $this->assertNull($result['machine']);
        $this->assertSame(['No machine.'], $result['missing']);
    }

    private function readiness(?AgentMachine $machine): CodingRuntimeReadinessService
    {
        return new class ($machine) extends CodingRuntimeReadinessService {
            public function __construct(private readonly ?AgentMachine $machine)
            {
            }

            public function checksForAgent(Agent $agent): array
            {
                return [];
            }

            public function machineFor(Agent $agent, array &$problems): ?AgentMachine
            {
                if ($this->machine === null) {
                    $problems[] = 'No machine.';
                }

                return $this->machine;
            }

            public function hostProblems(AgentMachine $machine, AppInterface $app, ?SshClient $client = null): array
            {
                return [];
            }
        };
    }
}
