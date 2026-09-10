<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Neuron\Tools\Mcp\RemoteMcpToolkit;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Models\Tool;

/**
 * An MCP server is unaudited third-party surface. A prospect who can steer a customer-facing agent
 * could steer it into acting on the company's Jira, so the ban is enforced twice — refused at grant
 * time so an admin is told, and filtered at resolve time because the marker can be added to an agent
 * that already holds the grant, and only the second check catches that.
 */
final class McpCustomerFacingGuardTest extends McpTestCase
{
    public function testAnInternalAgentResolvesTheMcpToolkit(): void
    {
        $tool = $this->grantableTool();
        $host = new InternalMcpHostStub($this->mcpApp, $this->mcpCompany);

        $this->assertInstanceOf(RemoteMcpToolkit::class, $host->resolve($tool));
    }

    public function testACustomerFacingAgentGetsNoMcpEvenWhenTheGrantExists(): void
    {
        $tool = $this->grantableTool();
        $host = new CustomerFacingMcpHostStub($this->mcpApp, $this->mcpCompany);

        $this->assertNull(
            $host->resolve($tool),
            'A grant written before the marker was added must still be filtered at runtime.'
        );
    }

    public function testAnMcpRowWithNoIntegrationResolvesToNothing(): void
    {
        $tool = $this->grantableTool();
        $tool->integrations_id = null;
        $tool->saveOrFail();

        $host = new InternalMcpHostStub($this->mcpApp, $this->mcpCompany);

        $this->assertNull($host->resolve($tool));
    }

    private function grantableTool(): Tool
    {
        $integration = $this->makeIntegration();
        $this->enableForCompany($integration);

        return $this->makeMcpTool($integration);
    }
}

class InternalMcpHostStub implements ProvidesToolDependencies
{
    use MergesRegisteredTools;

    public function __construct(
        private readonly Apps $mcpApp,
        private readonly Companies $mcpCompany,
    ) {
    }

    public function toolDependencyCandidates(): array
    {
        return [$this->mcpApp, $this->mcpCompany];
    }

    public function resolve(Tool $tool): ?object
    {
        return $this->resolveRegisteredMcpTool($tool);
    }
}

class CustomerFacingMcpHostStub extends InternalMcpHostStub implements ConversesWithCustomer
{
}
