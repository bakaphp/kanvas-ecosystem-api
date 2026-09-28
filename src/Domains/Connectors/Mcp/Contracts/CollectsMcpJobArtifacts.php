<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Contracts;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\McpAsyncJob;
use Kanvas\Workflow\Models\Integrations;

/**
 * A vendor that leaves files behind when a background job finishes — Browser Use writes a CSV into its
 * sandbox, which dies with the session. The class named by `integrations.metadata.artifacts_handler`
 * knows where that vendor keeps them and how to reach them.
 *
 * Implementations are resolved by class name and constructed with no arguments.
 */
interface CollectsMcpJobArtifacts
{
    /**
     * Arguments Kanvas adds to a job-starting call, whatever the model passed — the workspace a file
     * must be written to for it to outlive the run. Only keys the model left out are filled in.
     *
     * @return array<string, mixed>
     */
    public function defaultArguments(Agent $agent, Integrations $integration, string $remoteToolName): array;

    /**
     * The files this finished job produced, ready to hand to `addMultipleFilesFromUrl`. Download links
     * from these vendors expire in a minute, so the caller stores them at once rather than passing them on.
     *
     * @return list<array{url: string, name: string}>
     */
    public function collect(McpAsyncJob $job): array;
}
