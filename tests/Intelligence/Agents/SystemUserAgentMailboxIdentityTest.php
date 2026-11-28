<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

final class SystemUserAgentMailboxIdentityTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'intelligence'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useFakeProvider();
    }

    public function testAnAgentIsToldTheMailboxItOwns(): void
    {
        $agent = $this->agent();
        $this->connection('fake', 'ginny@kanvas.test', [
            'config' => [Connection::PERSONAL => true, 'agent_id' => $agent->getId()],
        ]);

        $this->assertStringContainsString('ginny@kanvas.test', $this->instructionsOf($agent));
    }

    public function testACompanyLineAnAgentAnswersIsNotItsMailbox(): void
    {
        $agent = $this->agent();
        $this->connection('fake', 'sales@kanvas.test', ['config' => ['agent_id' => $agent->getId()]]);

        $this->assertStringNotContainsString('sales@kanvas.test', $this->instructionsOf($agent));
    }

    public function testAnotherAgentsMailboxIsNotMine(): void
    {
        $this->connection('fake', 'sofia@kanvas.test', [
            'config' => [Connection::PERSONAL => true, 'agent_id' => $this->agent()->getId()],
        ]);

        $this->assertStringNotContainsString('sofia@kanvas.test', $this->instructionsOf($this->agent()));
    }

    private function instructionsOf(Agent $agent): string
    {
        $handler = new SystemUserAgent();
        $handler->setConfiguration(agent: $agent, user: auth()->user());

        return $handler->instructions();
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create();
    }
}
