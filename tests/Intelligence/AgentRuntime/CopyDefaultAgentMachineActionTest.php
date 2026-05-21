<?php

declare(strict_types=1);

namespace Tests\Intelligence\AgentRuntime;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Actions\CopyDefaultAgentMachineAction;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\TestCase;

class CopyDefaultAgentMachineActionTest extends TestCase
{
    public function testCopiesDefaultMachineToTargetCompany(): void
    {
        $app = app(Apps::class);
        $sourceCompany = auth()->user()->getCurrentCompany();
        $targetCompany = Companies::factory()->create();
        $sourceMachine = $this->createMachine($sourceCompany);

        $copiedMachine = new CopyDefaultAgentMachineAction(
            $sourceMachine,
            $targetCompany,
            $app,
        )->execute();

        $this->assertNotSame($sourceMachine->getId(), $copiedMachine->getId());
        $this->assertSame($app->getId(), (int) $copiedMachine->apps_id);
        $this->assertSame($targetCompany->getId(), (int) $copiedMachine->companies_id);
        $this->assertSame($sourceMachine->name . ' - Company ' . $targetCompany->getId(), $copiedMachine->name);
        $this->assertSame($sourceMachine->host, $copiedMachine->host);
        $this->assertSame($sourceMachine->ssh_port, $copiedMachine->ssh_port);
        $this->assertSame($sourceMachine->ssh_user, $copiedMachine->ssh_user);
        $this->assertSame($sourceMachine->ssh_private_key, $copiedMachine->ssh_private_key);
        $this->assertSame($sourceMachine->region, $copiedMachine->region);
        $this->assertSame($sourceMachine->port_range_start, $copiedMachine->port_range_start);
        $this->assertSame($sourceMachine->port_range_end, $copiedMachine->port_range_end);
        $this->assertSame($sourceMachine->max_agents, $copiedMachine->max_agents);
        $this->assertSame($sourceMachine->is_active, $copiedMachine->is_active);
    }

    public function testCopyIsIdempotentForSameCompanyAndHost(): void
    {
        $app = app(Apps::class);
        $sourceCompany = auth()->user()->getCurrentCompany();
        $targetCompany = Companies::factory()->create();
        $sourceMachine = $this->createMachine($sourceCompany);

        $firstCopy = new CopyDefaultAgentMachineAction($sourceMachine, $targetCompany, $app)->execute();
        $secondCopy = new CopyDefaultAgentMachineAction($sourceMachine, $targetCompany, $app)->execute();

        $this->assertSame($firstCopy->getId(), $secondCopy->getId());
        $this->assertSame(
            1,
            AgentMachine::where('apps_id', $app->getId())
                ->where('companies_id', $targetCompany->getId())
                ->where('host', $sourceMachine->host)
                ->where('is_deleted', 0)
                ->count()
        );
    }

    private function createMachine(Companies $company): AgentMachine
    {
        $app = app(Apps::class);

        $machine = new AgentMachine();
        $machine->apps_id = $app->getId();
        $machine->companies_id = $company->getId();
        $machine->name = 'Default Runtime Machine ' . fake()->uuid();
        $machine->host = 'copy-default-' . fake()->uuid();
        $machine->ssh_port = 2222;
        $machine->ssh_user = 'runtime';
        $machine->ssh_private_key = 'test-private-key';
        $machine->region = 'test-region';
        $machine->port_range_start = 27000;
        $machine->port_range_end = 27100;
        $machine->max_agents = 25;
        $machine->is_active = true;
        $machine->saveOrFail();

        return $machine;
    }
}
