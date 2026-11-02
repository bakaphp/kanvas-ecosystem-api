<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Coding\SelfHostedProgrammingAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Harness\DispatchHarnessCodingTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\FindTeammateTool;
use Kanvas\NervousSystem\Capability\Actions\SetAgentToolAction;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\TestCase;

/**
 * Who may be handed a tool that runs code on a Kanvas machine.
 *
 * The harness tools dispatch work into a container with the agent's git token and push branches with
 * it. Granting one to an agent on a customer thread is source access and code execution, reachable by
 * whoever is talking to it — so the grant is refused rather than trusted to a reviewer noticing.
 *
 * The test is the POSITIVE marker (`ConversesWithUser`), not "is not customer-facing": an agent that
 * declares neither marker is treated as internal everywhere else, and that default would hand a shell
 * to anything someone forgot to label.
 */
class SystemOnlyToolGrantTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testASystemAgentMayHoldACodingTool(): void
    {
        SetAgentToolAction::assertMayHold(
            $this->agentWithHandler(SelfHostedProgrammingAgent::class),
            $this->codingTool(),
        );

        // assertMayHold throws or does nothing; reaching here is the assertion.
        $this->assertTrue(true);
    }

    public function testACustomerFacingAgentIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/internal system agents only/');

        SetAgentToolAction::assertMayHold(
            $this->agentWithHandler(SalesAgent::class),
            $this->codingTool(),
        );
    }

    /**
     * The case the positive marker exists for. An agent with no handler declares nothing, and every
     * other gate in the codebase would read that as internal.
     */
    public function testAnAgentThatDeclaresNeitherMarkerIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/internal system agents only/');

        SetAgentToolAction::assertMayHold(
            $this->agentWithHandler(null),
            $this->codingTool(),
        );
    }

    public function testANonCodingToolIsUnaffected(): void
    {
        $tool = $this->codingTool();
        $tool->handler = 'Kanvas\\Intelligence\\Agents\\Neuron\\Tools\\CRM\\LeadRefTool';
        $tool->saveOrFail();

        SetAgentToolAction::assertMayHold($this->agentWithHandler(SalesAgent::class), $tool);

        $this->assertTrue(true);
    }

    /** The staff directory, with phone numbers, is not for a prospect-facing agent either. */
    public function testACustomerFacingAgentCannotBeGrantedTheFollowThroughTools(): void
    {
        $this->expectException(ValidationException::class);

        SetAgentToolAction::assertMayHold(
            $this->agentWithHandler(SalesAgent::class),
            $this->systemTool(FindTeammateTool::class),
        );
    }

    private function codingTool(): Tool
    {
        return $this->systemTool(DispatchHarnessCodingTaskTool::class);
    }

    private function systemTool(string $handler): Tool
    {
        $tool = new Tool();
        $tool->apps_id = 0;
        $tool->name = 'system_tool_' . random_int(1000, 9999);
        $tool->description = 'A tool for internal agents.';
        $tool->tool_type = ToolTypeEnum::SYSTEM->value;
        $tool->handler = $handler;
        $tool->frameworks = ['neuron'];
        $tool->version = '1.0.0';
        $tool->is_active = 1;
        $tool->is_deleted = 0;
        $tool->saveOrFail();

        return $tool;
    }

    private function agentWithHandler(?string $handler): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $type = AgentType::factory()->withAppId($app->getId())->create(['handler' => $handler]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId()]);
    }
}
