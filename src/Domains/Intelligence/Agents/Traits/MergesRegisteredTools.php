<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Mcp\RemoteMcpToolkit;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RequiresHumanCaller;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
use Kanvas\NervousSystem\Plan\Support\VerifierToolPolicy;
use Kanvas\NervousSystem\Plan\Support\WorkerToolPolicy;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Loop the agent's registered tools and turn them into runtime instances.
 *
 * Two access points:
 *  - mergeRegisteredTools(): for handlers that ship a hardcoded baseline
 *    (SalesAgent, RealStateAgent, …). Adds registry tools on top,
 *    deduped by handler class so a hardcoded tool is never re-instantiated
 *    via the registry.
 *  - resolveRegisteredTools(): for pure-registry handlers (KanvasGeneric*)
 *    that want zero baseline — same iteration, just starts from [].
 *
 * Customize resolution by overriding resolveRegisteredTool(). Default impl
 * instantiates $tool->handler when it has a no-arg constructor. Handlers
 * that need extras (sub-agents, parameterized handlers) override and may
 * delegate to defaultRegisteredToolResolver() for the standard case.
 */
trait MergesRegisteredTools
{
    /**
     * @return list<object>
     */
    protected function resolveRegisteredTools(
        ?Agent $agent,
        CapabilityFrameworkEnum $framework,
    ): array {
        return $this->mergeRegisteredTools([], $agent, $framework);
    }

    /**
     * @param array<int, object> $baseline
     * @return list<object>
     */
    protected function mergeRegisteredTools(
        array $baseline,
        ?Agent $agent,
        CapabilityFrameworkEnum $framework,
    ): array {
        // A hardcoded baseline tool is constructed by the subclass, so it never passed through
        // defaultRegisteredToolResolver() and would otherwise run with uninitialized tenant context —
        // which for a HasKanvasContext tool means an unscoped query. Fill it here so a tool is
        // tenant-bound whether the subclass hardcoded it or the registry resolved it.
        $baseline = array_map(fn (object $tool): object => $this->fillKanvasContext($tool), $baseline);

        if ($agent === null) {
            return array_values($baseline);
        }

        $seenHandlers = [];
        foreach ($baseline as $existing) {
            $seenHandlers[$existing::class] = true;
        }

        foreach (new CapabilityProvider()->getActiveTools($agent, $framework->value) as $registered) {
            /** @var Tool $registered */
            if ($registered->handler !== null && isset($seenHandlers[$registered->handler])) {
                continue;
            }

            $instance = $this->resolveRegisteredTool($registered);
            if ($instance === null) {
                continue;
            }

            $baseline[] = $instance;

            if ($registered->handler !== null) {
                $seenHandlers[$registered->handler] = true;
            }
        }

        return $this->applyWorkerBoundary(array_values($baseline));
    }

    /**
     * Strip tools a task worker may not hold. A single chokepoint on purpose: this is the one place
     * every toolset is assembled — baseline plus registry, Neuron and Laravel alike — so filtering
     * here is the difference between a boundary and a request. Outside a worker turn the policy is
     * inactive and this is a no-op.
     *
     * @param list<object> $tools
     * @return list<object>
     */
    protected function applyWorkerBoundary(array $tools): array
    {
        $verifying = VerifierToolPolicy::isActive();

        if (! $verifying && ! WorkerToolPolicy::isActive()) {
            return $tools;
        }

        // A toolkit answers to neither getName() nor name(), so an MCP grant would otherwise be kept
        // wholesale under the worker policy (unfiltered) and dropped wholesale under the verifier.
        // Expanding first makes every MCP tool face the policy on its own name, which is the only way
        // the boundary means anything here. Guidelines are lost in this mode — restricted execution is
        // the right place to trade prompt framing for an enforceable allow-list.
        $tools = $this->expandToolkits($tools);

        return array_values(array_filter(
            $tools,
            static function (object $tool) use ($verifying): bool {
                // The two tool trees name themselves differently; a tool that answers to neither is
                // kept under the worker policy — silently dropping something unidentifiable is the
                // worse failure there. Under the verifier it is dropped, because an unidentifiable
                // tool cannot be shown to be read-only and the allow-list must fail closed.
                $name = match (true) {
                    method_exists($tool, 'getName') => $tool->getName(),
                    method_exists($tool, 'name') => $tool->name(),
                    default => null,
                };

                if (! is_string($name)) {
                    return ! $verifying;
                }

                return $verifying
                    ? VerifierToolPolicy::permits($name)
                    : WorkerToolPolicy::permits($name);
            },
        ));
    }

    /**
     * @param list<object> $tools
     * @return list<object>
     */
    protected function expandToolkits(array $tools): array
    {
        $expanded = [];

        foreach ($tools as $tool) {
            if (! $tool instanceof ToolkitInterface) {
                $expanded[] = $tool;

                continue;
            }

            foreach ($tool->tools() as $inner) {
                $expanded[] = $inner;
            }
        }

        return $expanded;
    }

    /**
     * Override to add framework-specific resolution (e.g. wrap sub-agents).
     * Delegate to defaultRegisteredToolResolver() for the standard path.
     */
    protected function resolveRegisteredTool(Tool $tool): ?object
    {
        if ($tool->isMcp()) {
            return $this->resolveRegisteredMcpTool($tool);
        }

        if ($tool->agents_id !== null && method_exists($this, 'resolveRegisteredSubAgentTool')) {
            return $this->resolveRegisteredSubAgentTool($tool);
        }

        return $this->defaultRegisteredToolResolver($tool);
    }

    /**
     * An MCP row is backed by an `integrations` row rather than a PHP handler, and resolves to a
     * toolkit so Neuron expands it lazily into whatever the server currently publishes.
     */
    protected function resolveRegisteredMcpTool(Tool $tool): ?object
    {
        // Unaudited third-party tools must never reach a prospect — the same reasoning that keeps
        // read_file off a customer surface. The grant is refused at write time as well, but a marker
        // can be added to an agent that already holds one, and only this catches that.
        if ($this instanceof ConversesWithCustomer) {
            return null;
        }

        if ($tool->integrations_id === null) {
            return null;
        }

        $candidates = $this->dependencyCandidates();

        // Every connection belongs to one agent, which signs in with its own vendor account — so without
        // the calling agent there is no credential to act with, and nothing to resolve.
        $agent = $this->firstCandidateOfType($candidates, Agent::class);

        if (! $agent instanceof Agent) {
            return null;
        }

        return new RemoteMcpToolkit($agent, $tool);
    }

    protected function defaultRegisteredToolResolver(Tool $tool): ?object
    {
        if ($tool->handler === null || ! class_exists($tool->handler)) {
            return null;
        }

        $ctor = new ReflectionClass($tool->handler)->getConstructor();
        if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
            return $this->fillKanvasContext(new $tool->handler());
        }

        $candidates = $this->dependencyCandidates();

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $dependency = $this->matchToolDependency($param, $candidates);

            if ($dependency !== null) {
                $args[$param->getName()] = $dependency;

                continue;
            }

            if (! $param->isOptional()) {
                // A required dependency we can't satisfy — skip rather than fatal.
                return null;
            }
        }

        return $this->fillKanvasContext(new $tool->handler(...$args));
    }

    /**
     * Tools using HasKanvasContext take their tenant context via a withContext() setter, not the
     * constructor — so a registry-resolved instance (especially the no-arg path) would otherwise be
     * left with uninitialized app/company/user. Fill it from the same dependency candidates the
     * constructor path uses, so trait tools work whether hand-constructed or merged from the registry.
     */
    private function fillKanvasContext(object $tool): object
    {
        $uses = class_uses_recursive($tool);

        if (in_array(HasKanvasContext::class, $uses, true)) {
            $candidates = $this->dependencyCandidates();

            $app = $this->firstCandidateOfType($candidates, Apps::class);
            $company = $this->firstCandidateOfType($candidates, Companies::class);
            $user = $this->firstCandidateOfType($candidates, Users::class);
            $actingAgent = $this->firstCandidateOfType($candidates, Agent::class);

            if ($app instanceof Apps && $company instanceof Companies && $user instanceof Users) {
                $tool->withContext(
                    $app,
                    $company,
                    $user,
                    $actingAgent instanceof Agent ? $actingAgent : null,
                );
            }
        }

        // An admin-guarded tool authorizes on the HUMAN in the conversation, which is never a
        // toolDependencyCandidate — those carry actingUser(), i.e. the agent's own (usually admin)
        // user. requestingHuman() prefers the identified person over the turn's actor, because on
        // the @mention and channel surfaces the actor IS the agent's own user — passing that would
        // let whoever mentions the agent inherit its admin rights.
        if (in_array(GuardsAdminForTool::class, $uses, true)) {
            $human = method_exists($this, 'requestingHuman')
                ? $this->requestingHuman()
                : (property_exists($this, 'user') ? $this->user : null);

            if ($human instanceof Users) {
                $tool->forRequestingUser($human);
            }
        }

        // A self-service tool acts AS the caller, so it needs the same human the admin guard needs —
        // withContext() above carried actingUser(), which on a SystemUserAgent is the agent itself.
        if (in_array(RequiresHumanCaller::class, $uses, true)) {
            $human = method_exists($this, 'requestingHuman')
                ? $this->requestingHuman()
                : (property_exists($this, 'user') ? $this->user : null);

            if ($human instanceof Users) {
                $tool->forConversationHuman($human);
            }
        }

        // The record in scope can't come from toolDependencyCandidates() by type (Apps/Companies/Users
        // all extend Model), so hand the agent's own $entity to tools that opt in via HasEntityContext.
        if (in_array(HasEntityContext::class, $uses, true) && property_exists($this, 'entity')) {
            $tool->withEntity($this->entity);
        }

        return $tool;
    }

    /**
     * Hosts without a dependency context (non-agent trait users) contribute nothing, so every resolver
     * falls back to the historical all-optional-constructor behaviour.
     *
     * @return list<object>
     */
    private function dependencyCandidates(): array
    {
        return $this instanceof ProvidesToolDependencies
            ? $this->toolDependencyCandidates()
            : [];
    }

    /**
     * @param list<object> $candidates
     */
    private function firstCandidateOfType(array $candidates, string $class): ?object
    {
        foreach ($candidates as $candidate) {
            if ($candidate instanceof $class) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<object> $candidates
     */
    private function matchToolDependency(ReflectionParameter $param, array $candidates): ?object
    {
        $type = $param->getType();
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $expected = $type->getName();
        foreach ($candidates as $candidate) {
            if ($candidate instanceof $expected) {
                return $candidate;
            }
        }

        return null;
    }
}
