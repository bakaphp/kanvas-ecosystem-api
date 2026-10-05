<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\Stubs\Intelligence\RememberingSystemUserAgentStub;
use Tests\Stubs\Intelligence\SharedCompanyMemory;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * The end-to-end claim of company memory: a fact stated to a system agent in one session is in the
 * context of a fresh session, with a different thread, because memory belongs to the company.
 */
class SystemUserAgentMemoryTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    protected function setUp(): void
    {
        parent::setUp();

        SharedCompanyMemory::reset();
    }

    public function testAFactFromOneSessionIsRecalledInTheNext(): void
    {
        $user = auth()->user();
        $agentRecord = $this->makeAgentFor($user);

        $first = new RememberingSystemUserAgentStub();
        $first->setConfiguration(agent: $agentRecord, user: $user);
        $first->setThreadId('session-x');
        $first->chat(new UserMessage('For the record: our Q4 launch is on November 12, marketing confirmed it this morning.'));

        $memories = SharedCompanyMemory::all();
        $this->assertCount(1, $memories, 'The first turn is written to company memory');
        $this->assertSame($user->getCurrentCompany()->getId(), $memories[0]->getMetadata()['companies_id']);
        $this->assertSame($agentRecord->getId(), $memories[0]->getMetadata()['agent_id']);
        $this->assertSame('session-x', $memories[0]->getSourceName());

        $second = new RememberingSystemUserAgentStub();
        $second->setConfiguration(agent: $agentRecord, user: $user);
        $second->setThreadId('session-y');
        $second->chat(new UserMessage('When is the launch again?'));

        $prompt = end($second->systemPrompts);
        $this->assertIsString($prompt);
        $this->assertStringContainsString('November 12', $prompt, 'The second session reads the first one back from memory');
        $this->assertStringContainsString('Earlier conversation', $prompt);
    }
}
