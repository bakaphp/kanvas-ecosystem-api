<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Actions;

use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\McpAsyncJob;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Files a finished job produced land on a plan rather than in the conversation: a plan is addressable,
 * so the agent can hand it to another agent or a later run ("extract the rows from the file on plan 12")
 * instead of carrying a spreadsheet through its context.
 *
 * Returns null when the job produced nothing, which is the common case.
 */
class AttachMcpJobArtifactsToPlanAction
{
    public const string PLAN_TYPE = 'mcp_job';

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

        $plan = new CreatePlanAction(
            new PlanData(
                app: $agent->app,
                company: $agent->company,
                title: $this->title(),
                planType: self::PLAN_TYPE,
                agent: $agent,
                user: $user,
                entityNamespace: McpAsyncJob::class,
                entityId: $this->job->getId(),
                description: $this->description(array_keys($stored)),
                status: PlanStatusEnum::DONE,
            ),
        )->execute();

        foreach ($stored as $name => $file) {
            $plan->addFile($file, $name);
        }

        return $plan;
    }

    /**
     * Downloaded into Kanvas, never linked: the vendor's urls are presigned and dead within the minute,
     * and they need its credentials, so a stored link is a file nobody can open.
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
     * The whole collection is best-effort: a vendor that fails to hand its files over must not turn a
     * finished job into a failed one, and the agent still gets the job's own output.
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
