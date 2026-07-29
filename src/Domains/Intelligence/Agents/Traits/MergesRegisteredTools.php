<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Models\Agent;
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
        if (! in_array(HasKanvasContext::class, class_uses_recursive($tool), true)) {
            return $tool;
        }

        $candidates = $this instanceof ProvidesToolDependencies
            ? $this->toolDependencyCandidates()
            : [];

        $app = $this->firstCandidateOfType($candidates, Apps::class);
        $company = $this->firstCandidateOfType($candidates, Companies::class);
        $user = $this->firstCandidateOfType($candidates, Users::class);

        if ($app instanceof Apps && $company instanceof Companies && $user instanceof Users) {
            $tool->withContext($app, $company, $user);
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
