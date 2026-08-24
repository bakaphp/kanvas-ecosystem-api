<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
use Kanvas\Users\Models\Users;
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

        return array_values($baseline);
    }

    /**
     * Override to add framework-specific resolution (e.g. wrap sub-agents).
     * Delegate to defaultRegisteredToolResolver() for the standard path.
     */
    protected function resolveRegisteredTool(Tool $tool): ?object
    {
        if ($tool->agents_id !== null && method_exists($this, 'resolveRegisteredSubAgentTool')) {
            return $this->resolveRegisteredSubAgentTool($tool);
        }

        return $this->defaultRegisteredToolResolver($tool);
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

        // Hosts without a dependency context (non-agent trait users) fall back to
        // resolving only all-optional-constructor tools — the historical behaviour.
        $candidates = $this instanceof ProvidesToolDependencies
            ? $this->toolDependencyCandidates()
            : [];

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
            $candidates = $this instanceof ProvidesToolDependencies
                ? $this->toolDependencyCandidates()
                : [];

            $app = $this->firstCandidateOfType($candidates, Apps::class);
            $company = $this->firstCandidateOfType($candidates, Companies::class);
            $user = $this->firstCandidateOfType($candidates, Users::class);

            if ($app instanceof Apps && $company instanceof Companies && $user instanceof Users) {
                $tool->withContext($app, $company, $user);
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

        // The record in scope can't come from toolDependencyCandidates() by type (Apps/Companies/Users
        // all extend Model), so hand the agent's own $entity to tools that opt in via HasEntityContext.
        if (in_array(HasEntityContext::class, $uses, true) && property_exists($this, 'entity')) {
            $tool->withEntity($this->entity);
        }

        return $tool;
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
