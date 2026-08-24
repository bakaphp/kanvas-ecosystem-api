<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Services;

use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Traits\MergesRegisteredTools;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Override;

/**
 * Resolves an agent's granted tools without a Neuron handler — a hosted agent never constructs one,
 * since `AgentChatKernel` short-circuits first. Reuses the handlers' own `MergesRegisteredTools`, so
 * hosted and in-process agents with the same grants hold the same objects with the same tenant
 * context.
 *
 * Resolves against CLAUDE, and every NeuronAI tool carries that tag alongside `neuron` (see
 * `AgentToolDiscoveryService::withHostedFrameworks()`). Reusing the bare `neuron` tag here would
 * work at runtime but leaves the grant UI empty — it filters by the agent type's provider, so a
 * hosted agent could never be given a tool in the first place.
 */
class KanvasToolResolverService implements ProvidesToolDependencies
{
    use MergesRegisteredTools;

    public function __construct(
        protected readonly Agent $agent,
    ) {
    }

    /**
     * @return list<object>
     */
    public function resolve(): array
    {
        return $this->resolveRegisteredTools($this->agent, CapabilityFrameworkEnum::CLAUDE);
    }

    /**
     * Specific injectables before the generic entity, so a `Users` parameter resolves to the acting
     * user rather than something that merely happens to be one.
     *
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
