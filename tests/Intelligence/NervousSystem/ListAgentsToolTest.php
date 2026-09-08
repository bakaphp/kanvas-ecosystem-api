<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListAgentsTool;
use Kanvas\Intelligence\Agents\Types\OpenClawAgentHandler;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * An orchestrator could name what it wanted to hire and could resolve a teammate it already knew the
 * name of, but had no way to see who was on staff — so the cheapest correct move, assigning the agent
 * that already holds the tools, was the one it could not reach.
 *
 * The catalog and the grants live on `intelligence`, so it has to be listed for transactions or the
 * agents seeded here survive into the next test's roster.
 */
final class ListAgentsToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence'];

    private ?Companies $company = null;

    public function testItListsTheCompanyRosterWithTheIdsTheStaffingToolsNeed(): void
    {
        $agent = $this->neuronAgent('Newsroom');

        $result = $this->list();

        $row = $this->row($result, 'Newsroom');

        $this->assertSame($agent->getId(), $row['agent_id']);
        $this->assertTrue($row['can_execute_board_work']);
        $this->assertSame(1, $result['count']);
    }

    /** The roster is what an orchestrator staffs from; another tenant's agents must never be offerable. */
    public function testItNeverListsAnotherCompanysAgents(): void
    {
        $this->neuronAgent('Ours');

        Agent::factory()
            ->withAppId($this->kanvasApp()->getId())
            ->withCompanyId(Companies::factory()->create(['users_id' => $this->currentUser()->getId()])->getId())
            ->create(['name' => 'Theirs', 'user_id' => $this->currentUser()->getId()]);

        $names = array_column($this->list()['agents'], 'name');

        $this->assertContains('Ours', $names);
        $this->assertNotContains('Theirs', $names);
    }

    public function testItDoesNotListARetiredAgent(): void
    {
        $this->neuronAgent('Retired')->softDelete();

        $this->assertSame([], $this->list()['agents']);
    }

    public function testCapabilityKeepsOnlyAgentsHoldingAMatchingTool(): void
    {
        $mailer = $this->neuronAgent('Mailer');
        $mailer->selectedTools()->attach($this->tool('zzqq_send_email', 'Send a zzqq email to a person.')->getKey());
        $this->neuronAgent('Idle');

        $names = array_column($this->list(capability: 'zzqq email')['agents'], 'name');

        $this->assertSame(['Mailer'], $names);
    }

    public function testItReportsTheToolsAnAgentActuallyHolds(): void
    {
        $agent = $this->neuronAgent('Mailer');
        $agent->selectedTools()->attach($this->tool('zzqq_send_email', 'Send a zzqq email.')->getKey());

        $row = $this->row($this->list(), 'Mailer');

        $this->assertSame(1, $row['tool_count']);
        $this->assertSame(['zzqq_send_email'], $row['tools']);
    }

    /**
     * A container agent runs its own board elsewhere and cannot hold our plan tools, so offering it
     * for board work produces a plan nobody moves — discovered days later, on someone else's task.
     */
    public function testExecutorsOnlyDropsAnAgentThatCannotDoBoardWork(): void
    {
        $this->neuronAgent('Planner');
        $this->containerAgent('Sandbox');

        $all = array_column($this->list()['agents'], 'name');
        $executors = array_column($this->list(executorsOnly: true)['agents'], 'name');

        $this->assertContains('Sandbox', $all);
        $this->assertSame(['Planner'], $executors);
        $this->assertFalse($this->row($this->list(), 'Sandbox')['can_execute_board_work']);
    }

    /** Its own row read as a teammate is how a PM hires a second PM, or delegates work to itself. */
    public function testTheCallingAgentIsMarkedAsItself(): void
    {
        $caller = $this->neuronAgent('Me');
        $this->neuronAgent('Someone Else');

        $result = $this->list(caller: $caller);

        $this->assertTrue($this->row($result, 'Me')['is_you'] ?? false);
        $this->assertArrayNotHasKey('is_you', $this->row($result, 'Someone Else'));
    }

    public function testItNamesTheProjectsAnAgentIsAlreadyOn(): void
    {
        $agent = $this->neuronAgent('Busy');

        new AddProjectMemberAction(
            project: $this->project('Tally B2'),
            role: ProjectMemberRoleEnum::CONTRIBUTOR,
            agent: $agent,
        )->execute();

        $this->assertSame(['Tally B2'], $this->row($this->list(), 'Busy')['projects']);
    }

    /** An empty answer has to say what to do next, or it reads as "the platform has no agents". */
    public function testAnEmptyRosterPointsAtHiring(): void
    {
        $result = $this->list();

        $this->assertSame(0, $result['count']);
        $this->assertStringContainsString('hire_agent', $result['note']);
    }

    public function testANonAdminInTheConversationIsRefusedTheRoster(): void
    {
        $this->neuronAgent('Newsroom');

        $result = $this->list(requestingUser: Users::factory()->create());

        $this->assertSame([], $result['agents']);
        $this->assertStringContainsString('administrator', $result['error']);
    }

    /**
     * A refusal that came back as a bare empty list reads as "this company has no agents" — which
     * sends the caller to hire_agent to duplicate a teammate it was simply not allowed to see.
     */
    public function testTheRefusalDoesNotReadAsAnEmptyRoster(): void
    {
        $this->neuronAgent('Newsroom');

        $this->assertArrayHasKey('error', $this->list(requestingUser: Users::factory()->create()));
        $this->assertArrayNotHasKey('error', $this->list());
    }

    /**
     * A wake has no human in the turn, so the guard falls back to the agent's own user. Denying there
     * would leave "hire another one" as the only move an unattended PM could make.
     */
    public function testAnUnattendedRunStillReadsTheRoster(): void
    {
        $this->neuronAgent('Newsroom');

        $this->assertSame(['Newsroom'], array_column($this->list()['agents'], 'name'));
    }

    /** Authorized, so the refusal it gets is the tenant one and not the admin guard standing in front. */
    public function testItRefusesWithoutTenantContext(): void
    {
        $result = new ListAgentsTool()
            ->forRequestingUser($this->currentUser())
            ->__invoke();

        $this->assertSame([], $result['agents']);
        $this->assertStringContainsString('company context', $result['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function list(
        ?string $capability = null,
        ?bool $executorsOnly = null,
        ?Agent $caller = null,
        ?Users $requestingUser = null,
    ): array {
        return new ListAgentsTool($caller)
            ->withContext($this->kanvasApp(), $this->company(), $this->currentUser())
            ->forRequestingUser($requestingUser)
            ->__invoke(capability: $capability, executors_only: $executorsOnly);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function row(array $result, string $name): array
    {
        $row = array_column($result['agents'], null, 'name')[$name] ?? null;

        $this->assertIsArray($row, "\"{$name}\" is not in the roster.");

        return $row;
    }

    private function neuronAgent(string $name): Agent
    {
        return $this->agent($name, 'neuron', KanvasGenericNeuronAgent::class);
    }

    private function containerAgent(string $name): Agent
    {
        return $this->agent($name, 'openclaw', OpenClawAgentHandler::class);
    }

    private function agent(string $name, string $provider, string $handler): Agent
    {
        $type = AgentType::factory()->withAppId($this->kanvasApp()->getId())->create([
            'name' => ucfirst($provider) . ' ' . fake()->unique()->lexify('?????'),
            'provider' => $provider,
            'handler' => $handler,
        ]);

        return Agent::factory()
            ->withAppId($this->kanvasApp()->getId())
            ->withCompanyId($this->company()->getId())
            ->create([
                'name' => $name,
                'user_id' => $this->currentUser()->getId(),
                'agent_type_id' => $type->getId(),
                'is_active' => true,
            ]);
    }

    private function project(string $title): Project
    {
        return new CreateProjectAction(
            ProjectData::from(
                $this->kanvasApp(),
                $this->currentUser(),
                $this->company(),
                ['title' => $title, 'agent_id' => $this->neuronAgent('PM ' . fake()->unique()->lexify('?????'))->getId()],
            ),
        )->execute();
    }

    private function tool(string $name, string $description): Tool
    {
        return Tool::create([
            'apps_id' => $this->kanvasApp()->getId(),
            'name' => $name,
            'description' => $description,
            'tool_type' => 'system',
            'frameworks' => ['neuron'],
            'version' => '1.0.0',
            'is_active' => 1,
            'is_deleted' => 0,
        ]);
    }

    private function kanvasApp(): Apps
    {
        return app(Apps::class);
    }

    /** Its own company: the shared one accrues agents all suite long, and this asserts on a roster. */
    private function company(): Companies
    {
        return $this->company ??= Companies::factory()->create([
            'users_id' => $this->currentUser()->getId(),
        ]);
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
