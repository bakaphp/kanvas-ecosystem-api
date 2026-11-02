<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\CFOAgent;
use Kanvas\Intelligence\Agents\Neuron\Coding\ProgrammingAgent;
use Kanvas\Intelligence\Agents\Neuron\Commerce\CommerceAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\CustomerSuccess\CustomerUpdateAgent;
use Kanvas\Intelligence\Agents\Neuron\HumanResources\HumanResourcesAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\NervousSystem\Plan\Support\VerifierToolPolicy;
use Kanvas\NervousSystem\Plan\Support\WorkerToolPolicy;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\ToolInterface;
use Tests\TestCase;

/**
 * The file tools are GRANTED, never handed to every agent.
 *
 * read_file reaches any file the company owns, so making it universal put company-wide file access
 * on customer-facing agents and changed the toolset of every handler that had a curated one.
 *
 * `read_file` alone is now baseline on SystemUserAgent — that class is internal by construction
 * (ConversesWithUser) and its own baseline refuses a ConversesWithCustomer subclass, so neither
 * failure mode above can reach it. That is the ONLY widening: the write-side file tools stay
 * granted even there, and every handler outside the SystemUserAgent tree — the pure-registry
 * generic agent, the customer-facing SalesAgent — still gets nothing without a catalog grant.
 * The internal-baseline half is covered by {@see \Tests\Intelligence\Agents\SystemAgentReadFileBaselineTest}.
 */
class UniversalAgentToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function testTheProjectManagerCarriesTheFileToolsIntrinsically(): void
    {
        $names = $this->toolNames(ProjectManagerAgent::make());

        foreach (['read_file', 'attach_file_to_plan', 'attach_file_to_task', 'list_plan_files', 'list_task_files'] as $tool) {
            $this->assertContains($tool, $names, $tool . ' is missing from the PM');
        }
    }

    /**
     * Every handler outside the SystemUserAgent tree stays exactly as curated. A baseline addition
     * here is invisible until it changes an agent's answer — it broke NeuronDynamicSubAgentTest by
     * turning a deliberately single-tool agent into a three-tool one.
     */
    public function testNoOtherHandlerGetsFileToolsWithoutAGrant(): void
    {
        foreach ([KanvasGenericNeuronAgent::make(), SalesAgent::make()] as $handler) {
            $names = $this->toolNames($handler);

            foreach (['read_file', 'attach_file_to_task', 'list_task_files'] as $tool) {
                $this->assertNotContains($tool, $names, $handler::class . ' got ' . $tool . ' without a grant');
            }
        }
    }

    /**
     * An internal system agent may open a file it is pointed at and file what a teammate sends on the
     * task it was waiting on (Max, 2026-10-12). The plan-level file surface is still the PM's: it
     * costs a grant, so a teammate agent cannot quietly acquire the board's file management.
     */
    public function testSystemUserAgentGetsTaskFileToolsButNotPlanFileTools(): void
    {
        $names = $this->toolNames(SystemUserAgent::make());

        foreach (['read_file', 'attach_file_to_task', 'list_task_files'] as $tool) {
            $this->assertContains($tool, $names);
        }

        foreach (['attach_file_to_plan', 'list_plan_files'] as $tool) {
            $this->assertNotContains($tool, $names, 'SystemUserAgent got ' . $tool . ' without a grant');
        }
    }

    /**
     * Waiting on a person is the one thing every internal agent must be able to do: ask them, park the
     * task, be woken. The agents that curate their own tool list are the ones that silently lost it.
     */
    public function testEverySystemUserAgentCanAskWaitAndBeWoken(): void
    {
        foreach ($this->systemUserHandlers() as $handler) {
            $names = $this->toolNames($handler);

            foreach ([
                'find_teammate',
                'send_email_to_user',
                'open_follow_up',
                'get_nervous_system_task',
                'update_nervous_system_task_status',
                'attach_file_to_task',
                'list_task_files',
                'snooze_task',
                'schedule_agent_task',
                'schedule_reminder',
            ] as $tool) {
                $this->assertContains($tool, $names, $handler::class . ' cannot follow through: no ' . $tool);
            }
        }
    }

    /** A tool wired by hand and again through a shared set is two tools with one name. */
    public function testNoSystemUserAgentRegistersAToolTwice(): void
    {
        foreach ($this->systemUserHandlers() as $handler) {
            $names = $this->toolNames($handler);
            $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $count): bool => $count > 1));

            $this->assertSame([], $duplicates, $handler::class . ' registers a tool twice');
        }
    }

    /** The clock stays universal — souls name get_current_time, and a missing named tool kills the turn. */
    public function testTheClockIsStillUniversal(): void
    {
        $this->assertContains('get_current_time', $this->toolNames());
    }

    /** Both boundaries are name-based, so a rename that slips past them silently disarms the tool. */
    public function testBothTurnBoundariesPermitReadingFiles(): void
    {
        $this->assertTrue(WorkerToolPolicy::permits('read_file'), 'a granted worker cannot read its input');
        $this->assertTrue(VerifierToolPolicy::permits('read_file'), 'the verifier cannot open the deliverable');
        $this->assertTrue(VerifierToolPolicy::permits('list_plan_files'));
    }

    /**
     * @return list<object>
     */
    private function systemUserHandlers(): array
    {
        return [
            SystemUserAgent::make(),
            ProjectManagerAgent::make(),
            CommerceAgent::make(),
            CFOAgent::make(),
            AccountsPayableAgent::make(),
            AccountsReceivableAgent::make(),
            SalesManagerAgent::make(),
            ProgrammingAgent::make(),
            HumanResourcesAgent::make(),
            CustomerUpdateAgent::make(),
        ];
    }

    /**
     * @return list<string>
     */
    private function toolNames(?object $handler = null): array
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);

        $handler ??= KanvasGenericNeuronAgent::make();

        // The handler class on the type is what `conversesWithUser()` reads: the system-only tools
        // are withheld at runtime from an agent whose type does not say what it is.
        $type = AgentType::factory()->withAppId($app->getId())->create([
            'provider' => 'neuron',
            'handler' => $handler::class,
        ]);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['agent_type_id' => $type->getId()]);
        $handler->setConfiguration(agent: $agent, user: $user);

        return array_values(array_map(
            static fn (ToolInterface $tool): string => $tool->getName(),
            array_filter($handler->getTools(), static fn (object $t): bool => $t instanceof ToolInterface),
        ));
    }
}
