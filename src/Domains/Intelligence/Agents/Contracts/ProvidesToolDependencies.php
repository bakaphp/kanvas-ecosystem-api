<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Contracts;

/**
 * A tool host (an agent) that can supply concrete dependencies to inject into a
 * registry tool's constructor. MergesRegisteredTools uses this to instantiate
 * context-needing tools (e.g. CreateLeadTool) instead of silently skipping them.
 * Hosts without a tenant context simply don't implement it and fall back to
 * no-arg tool resolution.
 */
interface ProvidesToolDependencies
{
    /**
     * Injectable dependencies in priority order (specific injectables before the
     * generic entity, so a `Users` param resolves to the acting user rather than an
     * entity that also happens to be a Users).
     *
     * @return list<object>
     */
    public function toolDependencyCandidates(): array;
}
