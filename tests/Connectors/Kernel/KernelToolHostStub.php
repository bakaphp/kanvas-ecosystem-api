<?php

declare(strict_types=1);

namespace Tests\Connectors\Kernel;

use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Override;

/**
 * An agent's toolset builder with nothing in it but the agent — enough to exercise whether a catalog row
 * resolves to a usable tool for this agent, which is where the MCP-connection gate lives.
 */
class KernelToolHostStub implements ProvidesToolDependencies
{
    use MergesRegisteredTools;

    public function __construct(private readonly Agent $agent)
    {
    }

    public function resolve(Tool $tool): ?object
    {
        return $this->resolveRegisteredTool($tool);
    }

    /**
     * @return list<object>
     */
    #[Override]
    public function toolDependencyCandidates(): array
    {
        return array_values(array_filter([
            $this->agent->app,
            $this->agent->company,
            $this->agent->user,
            $this->agent,
        ]));
    }
}
