<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Jobs\ProcessAgentChatTurnJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\HumanResources\HumanResourcesAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Sessions\Models\Session;
use NeuronAI\Tools\ToolInterface;
use Tests\Stubs\Intelligence\CapturingSystemUserAgentStub;
use Tests\TestCase;

/**
 * `kanvas-artifact` blocks only render in the admin userChat; on Slack, email or a connector channel
 * the reader would get raw JSON. So the tool follows the surface, not the agent.
 */
final class RenderArtifactSurfaceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        CapturingSystemUserAgentStub::$lastRendersArtifacts = null;
    }

    public function testASystemAgentGetsTheToolOnlyWhenTheSurfaceRendersArtifacts(): void
    {
        $agent = $this->makeAgent(SystemUserAgent::class);

        $rendering = new SystemUserAgent();
        $rendering->setConfiguration($agent, user: auth()->user());
        $rendering->setRendersArtifacts(true);

        $plain = new SystemUserAgent();
        $plain->setConfiguration($agent, user: auth()->user());

        $this->assertContains('render_artifact', $this->toolNames($rendering->getTools()));
        $this->assertNotContains('render_artifact', $this->toolNames($plain->getTools()));
    }

    /**
     * HR reaches the baseline through parent::tools(); the PM skips it and calls identityTools()
     * directly — both must inherit the tool.
     */
    public function testSystemAgentSubclassesInheritTheTool(): void
    {
        foreach ([HumanResourcesAgent::class, ProjectManagerAgent::class] as $class) {
            $handler = new $class();
            $handler->setConfiguration($this->makeAgent($class), user: auth()->user());
            $handler->setRendersArtifacts(true);

            $this->assertContains('render_artifact', $this->toolNames($handler->getTools()), $class);
        }
    }

    public function testOnlySystemUserAgentsGetTheToolEvenOnUserChat(): void
    {
        foreach ([KanvasGenericNeuronAgent::class, SalesAgent::class] as $class) {
            $handler = new $class();
            $handler->setConfiguration($this->makeAgent($class), user: auth()->user());
            $handler->setRendersArtifacts(true);

            $this->assertNotContains('render_artifact', $this->toolNames($handler->getTools()), $class);
        }
    }

    public function testUserChatRendersArtifacts(): void
    {
        $agent = $this->makeAgent(CapturingSystemUserAgentStub::class);

        $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) { session_id }
            }
        ', [
            'input' => ['agent_id' => (string) $agent->getId(), 'message' => 'How many vacation days do I have?'],
        ])->assertSuccessful();

        $this->assertTrue(CapturingSystemUserAgentStub::$lastRendersArtifacts);
    }

    public function testQueuedUserChatTurnRendersArtifacts(): void
    {
        $agent = $this->makeAgent(CapturingSystemUserAgentStub::class);

        $sessionId = $this->graphQL('
            mutation($input: UserChatInput!) {
                aiAgentUserChat(input: $input) { session_id }
            }
        ', [
            'input' => ['agent_id' => (string) $agent->getId(), 'message' => 'Seed the session'],
        ])->json('data.aiAgentUserChat.session_id');

        CapturingSystemUserAgentStub::$lastRendersArtifacts = null;

        new ProcessAgentChatTurnJob(
            app: app(Apps::class),
            agent: $agent,
            session: Session::where('uuid', $sessionId)->firstOrFail(),
            user: auth()->user(),
            message: 'Queued turn',
        )->handle();

        $this->assertTrue(CapturingSystemUserAgentStub::$lastRendersArtifacts);
    }

    public function testOtherSurfacesDoNotRenderArtifacts(): void
    {
        $agent = $this->makeAgent(CapturingSystemUserAgentStub::class);

        new AgentChatKernel(
            agent: $agent,
            session: null,
            message: 'Reply on Slack',
            user: auth()->user(),
            persistConversation: false,
        )->execute();

        $this->assertFalse(CapturingSystemUserAgentStub::$lastRendersArtifacts);
    }

    private function makeAgent(string $handler): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => $handler]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId(), 'user_id' => auth()->user()->getId()]);
    }

    /**
     * @param array<int, object> $tools
     * @return list<string>
     */
    private function toolNames(array $tools): array
    {
        return array_values(array_map(
            fn (ToolInterface $tool): string => $tool->getName(),
            array_filter($tools, fn (object $tool): bool => $tool instanceof ToolInterface),
        ));
    }
}
