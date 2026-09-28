<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Connectors\Mcp\Actions\ConnectMcpServerAction;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Mcp\RemoteMcpToolkit;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Actions\SetAgentToolAction;
use Kanvas\NervousSystem\Capability\Models\Tool;

/**
 * An MCP server is unaudited third-party surface. A prospect who can steer a customer-facing agent
 * could steer it into acting on the company's Jira, so the ban is enforced twice — refused when the
 * server is granted or connected so an admin is told, and filtered at resolve time because the marker
 * can be added to an agent that already holds the grant, and only the second check catches that.
 */
final class McpCustomerFacingGuardTest extends McpTestCase
{
    public function testAnInternalAgentResolvesTheMcpToolkit(): void
    {
        $host = new InternalMcpHostStub($this->makeAgent());

        $this->assertInstanceOf(RemoteMcpToolkit::class, $host->resolve($this->mcpTool()));
    }

    public function testACustomerFacingHostGetsNoMcpEvenWhenTheGrantExists(): void
    {
        $host = new CustomerFacingMcpHostStub($this->makeAgent());

        $this->assertNull(
            $host->resolve($this->mcpTool()),
            'A grant written before the marker was added must still be filtered at runtime.'
        );
    }

    public function testAnMcpRowWithNoIntegrationResolvesToNothing(): void
    {
        $tool = $this->mcpTool();
        $tool->integrations_id = null;
        $tool->saveOrFail();

        $this->assertNull(new InternalMcpHostStub($this->makeAgent())->resolve($tool));
    }

    public function testAHostWithoutAnAgentResolvesToNothing(): void
    {
        // Every connection belongs to one agent — with no agent there is no credential to act with.
        $this->assertNull(new InternalMcpHostStub(null)->resolve($this->mcpTool()));
    }

    public function testACustomerFacingAgentCannotBeGrantedAnMcpServer(): void
    {
        $this->expectException(ValidationException::class);

        new SetAgentToolAction(
            agent: $this->makeAgent(SalesAgent::class),
            tool: $this->mcpTool(),
            enabled: true,
            actor: $this->mcpUser,
        )->execute();
    }

    public function testACustomerFacingAgentCannotBeConnectedAndKeepsNoCredential(): void
    {
        $tool = $this->mcpTool();
        $agent = $this->makeAgent(SalesAgent::class);

        try {
            new ConnectMcpServerAction(
                agent: $agent,
                tool: $tool,
                actor: $this->mcpUser,
                method: McpAuthEnum::BEARER,
                grant: ['token' => 'secret'],
            )->execute();
            $this->fail('A customer-facing agent must never be connected to an MCP server.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertNull($this->credentials($agent, $tool->integration)->rawToken());
        $this->assertNull($this->grantFor($agent, $tool));
    }

    private function mcpTool(): Tool
    {
        return $this->makeMcpTool($this->makeIntegration());
    }
}

class InternalMcpHostStub implements ProvidesToolDependencies
{
    use MergesRegisteredTools;

    public function __construct(
        private readonly ?Agent $agent,
    ) {
    }

    public function toolDependencyCandidates(): array
    {
        return $this->agent === null ? [] : [$this->agent];
    }

    public function resolve(Tool $tool): ?object
    {
        return $this->resolveRegisteredMcpTool($tool);
    }
}

class CustomerFacingMcpHostStub extends InternalMcpHostStub implements ConversesWithCustomer
{
}
