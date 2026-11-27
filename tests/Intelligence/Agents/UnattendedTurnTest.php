<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Actions\Chat\WakeAgentInSessionAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\CapturingSystemUserAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesEmployees;

final class UnattendedTurnTest extends TestCase
{
    use DatabaseTransactions;
    use MakesEmployees;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    private Agent $agent;
    private Users $human;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantApp = app(Apps::class);
        $this->human = $this->makeAdmin();

        $type = AgentType::factory()->withAppId($tenantApp->getId())->create([
            'provider' => 'neuron',
            'handler' => CapturingSystemUserAgentStub::class,
        ]);
        $this->agent = Agent::factory()
            ->withAppId($tenantApp->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['agent_type_id' => $type->getId()]);

        CapturingSystemUserAgentStub::$lastUnattended = null;
        CapturingSystemUserAgentStub::$lastRequestingHuman = null;
        CapturingSystemUserAgentStub::$lastPersonAtKeyboard = null;
    }

    public function testAnAttendedTurnHasThePersonAtTheKeyboard(): void
    {
        $handler = $this->handler();

        $this->assertFalse($handler->isUnattendedTurn());
        $this->assertTrue($this->human->is($handler->personAtKeyboard()));
    }

    public function testAnUnattendedTurnHasNobodyAtTheKeyboardEvenWhenItRunsAsAnAdmin(): void
    {
        $this->assertTrue($this->human->isAdmin());
        $handler = $this->handler();
        $handler->setUnattendedTurn(true);

        $this->assertNull($handler->personAtKeyboard());
        $this->assertTrue($this->human->is($handler->personAsking()));
    }

    public function testTheKernelCarriesBothFactsToTheHandler(): void
    {
        $other = Users::factory()->create();

        new AgentChatKernel(
            agent: $this->agent,
            session: null,
            message: 'Scheduled check',
            user: $this->human,
            persistConversation: false,
            unattended: true,
            conversationHuman: $other,
        )->execute();

        $this->assertTrue(CapturingSystemUserAgentStub::$lastUnattended);
        $this->assertTrue($other->is(CapturingSystemUserAgentStub::$lastRequestingHuman));
        $this->assertNull(CapturingSystemUserAgentStub::$lastPersonAtKeyboard);
    }

    public function testAnOrdinaryKernelTurnIsAttended(): void
    {
        new AgentChatKernel(
            agent: $this->agent,
            session: null,
            message: 'Hello',
            user: $this->human,
            persistConversation: false,
        )->execute();

        $this->assertFalse(CapturingSystemUserAgentStub::$lastUnattended);
        $this->assertTrue($this->human->is(CapturingSystemUserAgentStub::$lastPersonAtKeyboard));
    }

    public function testAScheduledWakeIsUnattended(): void
    {
        $session = Session::firstOrCreateForEntity($this->agent);

        new WakeAgentInSessionAction(
            agent: $this->agent,
            session: $session,
            instruction: 'Every Monday send me the sales report',
            user: $this->human,
        )->execute();

        $this->assertTrue(CapturingSystemUserAgentStub::$lastUnattended);
        $this->assertNull(CapturingSystemUserAgentStub::$lastPersonAtKeyboard);
    }

    private function handler(): CapturingSystemUserAgentStub
    {
        $handler = new CapturingSystemUserAgentStub();
        $handler->setConfiguration(agent: $this->agent, user: $this->human);

        return $handler;
    }
}
