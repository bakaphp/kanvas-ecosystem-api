<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Actions;

use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\McpAsyncJob;
use Kanvas\NervousSystem\Plan\Actions\AttachAgentFilesToPlanAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Files a finished job produced land on a plan, which is addressable — the agent can hand it on instead
 * of carrying a spreadsheet through its context. Null when the job produced nothing.
 */
class AttachMcpJobArtifactsToPlanAction
{
    public const string PLAN_TYPE = AttachAgentFilesToPlanAction::PLAN_TYPE;

    /**
     * @param list<array{url: string, name: string}> $artifacts
     */
    public function __construct(
        private readonly McpAsyncJob $job,
        private readonly array $artifacts,
    ) {
    }

    public function execute(): ?Plan
    {
        $agent = $this->job->agent;
        $user = $this->job->user ?? $agent?->user;

        if ($this->artifacts === [] || $agent === null || $user === null) {
            return null;
        }

        $stored = $this->store($agent, $user);

        if ($stored === []) {
            return null;
        }

        return new AttachAgentFilesToPlanAction(
            agent: $agent,
            user: $user,
            files: $stored,
            title: $this->title(),
            description: $this->description(array_keys($stored)),
            entityNamespace: McpAsyncJob::class,
            entityId: $this->job->getId(),
        )->execute();
    }

    /**
     * Downloaded, never linked: these urls are presigned, dead within the minute and need the vendor's
     * credentials.
     *
     * @return array<string, Filesystem> file name => the stored file
     */
    private function store(Agent $agent, Users $user): array
    {
        $filesystem = new FilesystemServices($agent->app, $agent->company);
        $stored = [];

        foreach ($this->artifacts as $artifact) {
            try {
                $stored[$artifact['name']] = $filesystem->uploadFileFromUrl($artifact['url'], $user, $artifact['name']);
            } catch (Throwable $e) {
                // One unreachable file must not cost the others, nor fail the job that produced them.
                report($e);
            }
        }

        return $stored;
    }

    private function title(): string
    {
        return sprintf('%s output — %s', $this->job->start_tool, $this->job->external_id);
    }

    /**
     * @param list<string> $names
     */
    private function description(array $names): string
    {
        return sprintf(
            "Files produced by the `%s` job %s on %s.\n\n%s",
            $this->job->start_tool,
            $this->job->external_id,
            $this->job->integration?->name ?? 'an MCP server',
            implode("\n", array_map(static fn (string $name): string => '- ' . $name, $names)),
        );
    }

    /**
     * Best-effort: a vendor failing to hand its files over must not turn a finished job into a failed one.
     *
     * @return list<array{url: string, name: string}>
     */
    public static function collectFor(McpAsyncJob $job): array
    {
        try {
            $integration = $job->integration;
            $collector = $integration === null
                ? null
                : McpServerConfig::fromIntegration($integration)->artifactCollector();

            return $collector?->collect($job) ?? [];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
