<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

trait CreatesAgentMachines
{
    /**
     * A saved machine on the current app and company that nothing can actually connect to.
     */
    protected function createAgentMachine(): AgentMachine
    {
        $machine = new AgentMachine();
        $machine->apps_id = app(Apps::class)->getId();
        $machine->companies_id = auth()->user()->getCurrentCompany()->getId();
        $machine->name = 'Coding machine ' . uniqid();
        $machine->host = '127.0.0.1';
        $machine->ssh_port = 22;
        $machine->ssh_user = 'kanvas';
        $machine->ssh_private_key = 'test-key';
        $machine->region = 'us-east-1';
        $machine->port_range_start = 20000;
        $machine->port_range_end = 20100;
        $machine->max_agents = 10;
        $machine->is_active = true;
        $machine->saveOrFail();

        return $machine;
    }
}
