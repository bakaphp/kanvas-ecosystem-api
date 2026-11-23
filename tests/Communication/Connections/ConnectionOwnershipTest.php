<?php

declare(strict_types=1);

namespace Tests\Communication\Connections;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

/**
 * The agent that owns a number or mailbox is the one that answers on it, and it is only ever an
 * agent of the connection's own company.
 */
final class ConnectionOwnershipTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    public function testAConnectionIsOwnedByTheAgentItsConfigNames(): void
    {
        $agent = $this->agentOf($this->currentCompany());
        $connection = $this->connection('fake', '+18095550301', ['config' => ['agent_id' => $agent->getId()]]);

        $this->assertTrue($connection->agent()->is($agent));
        $this->assertNull($this->connection('fake', '+18095550302')->agent(), 'nobody owns it, nobody answers');
    }

    public function testAnotherCompanysAgentNeverOwnsOrIsHandedAConnection(): void
    {
        $foreign = $this->agentOf($this->otherCompany());
        $owned = $this->connection('fake', '+18095550303', ['config' => ['agent_id' => $foreign->getId()]]);
        $free = $this->connection('fake', '+18095550304');

        $this->assertNull($owned->agent());
        $this->assertNull($free->candidateOwner($foreign->getId()));
    }

    public function testOnlyAConnectionNobodyOwnsCanBeHandedToAnAgent(): void
    {
        $first = $this->agentOf($this->currentCompany());
        $second = $this->agentOf($this->currentCompany());
        $connection = $this->connection('fake', '+18095550305');

        $this->assertTrue($connection->candidateOwner($first->getId())->is($first));

        $connection->handTo($first, ['personal' => true]);

        $this->assertTrue($connection->agent()->is($first));
        $this->assertTrue($connection->personalOwner()->is($first));
        $this->assertNull($connection->candidateOwner($second->getId()), 'an owner is never overridden');
    }

    public function testADisconnectingOrDisconnectedConnectionIsNotLive(): void
    {
        $ready = $this->connection('fake', '+18095550306');
        $disconnecting = $this->connection('fake', '+18095550307', ['setup_status' => SetupStatusEnum::DISCONNECTING]);
        $disconnected = $this->connection('fake', '+18095550308', ['setup_status' => SetupStatusEnum::DISCONNECTED]);

        $live = Connection::query()
            ->live()
            ->whereKey([$ready->getId(), $disconnecting->getId(), $disconnected->getId()])
            ->pluck('id')
            ->all();

        $this->assertSame([$ready->getId()], $live);
        $this->assertTrue($ready->isLive());
        $this->assertFalse($disconnecting->isLive());
        $this->assertFalse($disconnected->isLive());
    }

    private function agentOf(Companies $company): Agent
    {
        return Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($company->getId())
            ->create();
    }
}
