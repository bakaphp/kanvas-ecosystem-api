<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithUser;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\CFOAgent;
use Kanvas\Intelligence\Agents\Neuron\BaseRagAgent;
use NeuronAI\RAG\RAG;
use ReflectionMethod;
use Tests\TestCase;

final class CFOAgentSystemUserTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence'];

    private function makeCfoAgent(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['user_id' => $user->getId()]);
    }

    /**
     * The CFO's audience is company staff, so it is an internal teammate — and moving it onto
     * SystemUserAgent must not cost it the knowledge retrieval it had as a bare BaseRagAgent.
     */
    public function testIsAnInternalTeammateThatStillDoesRag(): void
    {
        $this->assertTrue(is_subclass_of(CFOAgent::class, ConversesWithUser::class));
        $this->assertFalse(is_subclass_of(CFOAgent::class, ConversesWithCustomer::class));
        $this->assertTrue(is_subclass_of(CFOAgent::class, BaseRagAgent::class));
        $this->assertTrue(is_subclass_of(CFOAgent::class, RAG::class));
    }

    public function testInstructionsCarryTheCfoGuidanceAndTheAgentIdentity(): void
    {
        $handler = new CFOAgent();
        $handler->setConfiguration(agent: $this->makeCfoAgent(), user: auth()->user());

        $instructions = $handler->instructions();

        $this->assertStringContainsString('query_data_freshness FIRST', $instructions);
        $this->assertStringContainsString('Due to Employees', $instructions);
        $this->assertStringContainsString('You ARE a Kanvas user', $instructions);
        // The hand-rolled SystemPrompt this replaced silently skipped the platform context.
        $this->assertStringContainsString('Kanvas is the orchestrator', $instructions);
    }

    public function testKeepsItsFinanceToolsOnTopOfTheSystemUserBaseline(): void
    {
        $handler = new CFOAgent();
        $handler->setConfiguration(agent: $this->makeCfoAgent(), user: auth()->user());

        /** @var array<int, object> $tools */
        $tools = new ReflectionMethod($handler, 'tools')->invoke($handler);
        $names = array_map(
            static fn (object $tool): string => method_exists($tool, 'getName') ? (string) $tool->getName() : (string) ($tool->name ?? ''),
            $tools,
        );

        $this->assertContains('query_data_freshness', $names);
        $this->assertContains('query_balance_sheet', $names);
        $this->assertContains('query_cash_position', $names);
        // Inherited from SystemUserAgent: company-wide self-memory, fine for an internal audience.
        $this->assertContains('read_my_ledger', $names);
    }
}
