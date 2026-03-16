<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\OpenClaw;

use Tests\Connectors\Traits\HasOpenClawConfiguration;
use Tests\TestCase;

class AgentMachineCrudTest extends TestCase
{
    use HasOpenClawConfiguration;

    public function testCreateAgentMachine(): void
    {
        $input = [
            'name' => 'Test Machine ' . fake()->word(),
            'host' => '192.168.1.100',
            'ssh_port' => 22,
            'ssh_user' => 'deploy',
            'ssh_private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----',
            'region' => 'us-east-1',
            'port_range_start' => 20000,
            'port_range_end' => 30000,
            'max_agents' => 50,
        ];

        $this->graphQL('
            mutation($input: AgentMachineInput!) {
                createAgentMachine(input: $input) {
                    id
                    name
                    host
                    ssh_port
                    ssh_user
                    region
                    port_range_start
                    port_range_end
                    max_agents
                    is_active
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAgentMachine' => [
                    'name' => $input['name'],
                    'host' => '192.168.1.100',
                    'ssh_port' => 22,
                    'region' => 'us-east-1',
                    'max_agents' => 50,
                    'is_active' => true,
                ],
            ],
        ]);
    }

    public function testUpdateAgentMachine(): void
    {
        $machine = $this->createTestMachine();

        $updateInput = [
            'name' => 'Updated Machine ' . fake()->word(),
            'max_agents' => 200,
            'is_active' => false,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateAgentMachineInput!) {
                updateAgentMachine(id: $id, input: $input) {
                    id
                    name
                    max_agents
                    is_active
                }
            }
        ', ['id' => $machine->getId(), 'input' => $updateInput])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateAgentMachine' => [
                    'name' => $updateInput['name'],
                    'max_agents' => 200,
                    'is_active' => false,
                ],
            ],
        ]);
    }

    public function testDeleteAgentMachine(): void
    {
        $machine = $this->createTestMachine();

        $this->graphQL('
            mutation($id: ID!) {
                deleteAgentMachine(id: $id)
            }
        ', ['id' => $machine->getId()])
        ->assertSuccessful()
        ->assertJson(['data' => ['deleteAgentMachine' => true]]);
    }

    public function testListAgentMachines(): void
    {
        $this->createTestMachine();

        $this->graphQL('
            query {
                agentMachines {
                    data {
                        id
                        name
                        host
                        region
                        is_active
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'agentMachines' => [
                    'data' => [
                        ['id', 'name', 'host', 'region', 'is_active'],
                    ],
                ],
            ],
        ]);
    }

    public function testCreateAgentMachineWithDefaults(): void
    {
        $input = [
            'name' => 'Minimal Machine ' . fake()->word(),
            'host' => '10.0.0.1',
            'ssh_user' => 'admin',
            'ssh_private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----',
        ];

        $this->graphQL('
            mutation($input: AgentMachineInput!) {
                createAgentMachine(input: $input) {
                    id
                    ssh_port
                    port_range_start
                    port_range_end
                    max_agents
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAgentMachine' => [
                    'ssh_port' => 22,
                    'port_range_start' => 20000,
                    'port_range_end' => 30000,
                    'max_agents' => 100,
                ],
            ],
        ]);
    }
}
