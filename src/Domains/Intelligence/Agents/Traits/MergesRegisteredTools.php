<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Kanvas\Intelligence\Agents\Contracts\ProvidesToolDependencies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Enums\CapabilityFrameworkEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
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
        return $this->defaultRegisteredToolResolver($tool);
    }

    protected function defaultRegisteredToolResolver(Tool $tool): ?object
    {
        if ($tool->handler === null || ! class_exists($tool->handler)) {
            return null;
        }

        $ctor = new ReflectionClass($tool->handler)->getConstructor();
        if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
            return new $tool->handler();
        }

        // A tool with constructor params: inject them by type from the host's
        // dependency context (Apps/Companies/Users/Session/Agent/entity). Hosts that
        // don't provide context resolve only tools whose params are all optional —
        // the historical behaviour.
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

        return new $tool->handler(...$args);
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
