<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Models\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\Stubs\Intelligence\RememberingCustomerAgentStub;
use Tests\Stubs\Intelligence\SharedCompanyMemory;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

/**
 * A customer-facing agent remembers and improves with its customer: what prospect A said comes back
 * in A's next session, on any thread, and never in prospect B's.
 */
class CustomerFacingAgentMemoryTest extends TestCase
{
    use DatabaseTransactions;
    use MakesAgents;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'crm'];

    protected function setUp(): void
    {
        parent::setUp();

        SharedCompanyMemory::reset();
    }

    public function testAProspectIsRememberedAcrossSessionsAndNeverLeaksToAnotherProspect(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $agentRecord = $this->makeAgentFor($user);

        $prospectA = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();
        $prospectB = People::factory()->withAppId($app->getId())->withCompanyId($company->getId())->create();

        $this->agent($agentRecord, $prospectA, 'a-whatsapp')
            ->chat(new UserMessage('I am looking for the blue 2024 Civic, my budget is 22 thousand and I can only come on Saturdays.'));

        $returning = $this->agent($agentRecord, $prospectA, 'a-email');
        $returning->chat(new UserMessage('Any news on the car we talked about?'));

        $prompt = (string) end($returning->modelInputs);
        $this->assertStringContainsString('blue 2024 Civic', $prompt, 'Prospect A is remembered on a new channel and session');
        $this->assertStringContainsString('Earlier conversation', $prompt);

        $stranger = $this->agent($agentRecord, $prospectB, 'b-whatsapp');
        $stranger->chat(new UserMessage('Any news on the car we talked about?'));

        $this->assertStringNotContainsString('Civic', (string) end($stranger->modelInputs), 'Prospect B never sees prospect A');
    }

    private function agent(Agent $record, People $prospect, string $thread): RememberingCustomerAgentStub
    {
        $agent = new RememberingCustomerAgentStub();
        $agent->setConfiguration(agent: $record, entity: $prospect, user: auth()->user());
        $agent->setThreadId($thread);

        return $agent;
    }
}
