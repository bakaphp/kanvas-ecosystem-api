<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
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
 * on customer-facing agents and changed the toolset of every handler that had a curated one. Only
 * the PM carries them intrinsically; everyone else gets them through the catalog, per agent.
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
     * Every other handler stays exactly as curated. A baseline addition here is invisible until it
     * changes an agent's answer — it broke NeuronDynamicSubAgentTest by turning a deliberately
     * single-tool agent into a three-tool one.
     */
    public function testNoOtherHandlerGetsFileToolsWithoutAGrant(): void
    {
        foreach ([KanvasGenericNeuronAgent::make(), SystemUserAgent::make(), SalesAgent::make()] as $handler) {
            $names = $this->toolNames($handler);

            foreach (['read_file', 'attach_file_to_task', 'list_task_files'] as $tool) {
                $this->assertNotContains($tool, $names, $handler::class . ' got ' . $tool . ' without a grant');
            }
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
     * @return list<string>
     */
    private function toolNames(?object $handler = null): array
    {
        /** @var Users $user */
        $user = auth()->user();
        $app = app(Apps::class);

        $type = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['agent_type_id' => $type->getId()]);

        $handler ??= KanvasGenericNeuronAgent::make();
        $handler->setConfiguration(agent: $agent, user: $user);

        return array_values(array_map(
            static fn (ToolInterface $tool): string => $tool->getName(),
            array_filter($handler->getTools(), static fn (object $t): bool => $t instanceof ToolInterface),
        ));
    }
}
