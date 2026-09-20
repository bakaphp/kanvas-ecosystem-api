<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrowserUse\Services;

use Carbon\CarbonImmutable;
use Kanvas\Connectors\BrowserUse\Client;
use Kanvas\Connectors\BrowserUse\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mcp\Contracts\CollectsMcpJobArtifacts;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\McpAsyncJob;
use Kanvas\Workflow\Models\Integrations;
use Override;
use Throwable;

/**
 * A session's own disk dies with it, so a workspace is what makes the files it writes outlive the run.
 * Downloads are kept per session either way.
 */
class BrowserUseArtifactCollector implements CollectsMcpJobArtifacts
{
    /** `send_task` inherits the session's workspace, so only a new session needs one. */
    private const string SESSION_TOOL = 'run_session';

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function defaultArguments(Agent $agent, Integrations $integration, string $remoteToolName): array
    {
        if ($remoteToolName !== self::SESSION_TOOL) {
            return [];
        }

        $workspaceId = $this->workspaceFor($agent, $integration);

        return $workspaceId === null ? [] : ['workspace_id' => $workspaceId];
    }

    /**
     * @return list<array{url: string, name: string}>
     */
    #[Override]
    public function collect(McpAsyncJob $job): array
    {
        $agent = $job->agent;
        $integration = $job->integration;

        if ($agent === null || $integration === null) {
            return [];
        }

        $client = Client::forAgent($agent, $integration);

        if ($client === null) {
            return [];
        }

        $workspaceId = $this->storedWorkspace($agent);

        $written = $workspaceId === null
            ? []
            : $this->writtenBy($job, $client->workspaceFiles($workspaceId));

        return [
            ...$written,
            ...$this->asArtifacts($client->browserDownloads($job->external_id)),
        ];
    }

    /**
     * @param list<array{url: string, name: string, modified_at: CarbonImmutable|null}> $files
     * @return list<array{url: string, name: string}>
     */
    private function writtenBy(McpAsyncJob $job, array $files): array
    {
        // A minute of slack: the row is written when the vendor accepted the task, and its clock is not ours.
        $startedAt = $job->created_at?->subMinute();

        return $this->asArtifacts(array_values(array_filter(
            $files,
            static fn (array $file): bool => $startedAt === null
                || $file['modified_at'] === null
                || $file['modified_at']->greaterThanOrEqualTo($startedAt)
        )));
    }

    /**
     * @param list<array{url: string, name: string, modified_at: CarbonImmutable|null}> $files
     * @return list<array{url: string, name: string}>
     */
    private function asArtifacts(array $files): array
    {
        return array_map(
            static fn (array $file): array => ['url' => $file['url'], 'name' => $file['name']],
            $files
        );
    }

    /**
     * One workspace per company, created on first use and remembered. A failure here must not stop the
     * job starting — the run just goes back to losing whatever it writes.
     */
    private function workspaceFor(Agent $agent, Integrations $integration): ?string
    {
        $stored = $this->storedWorkspace($agent);

        if ($stored !== null) {
            return $stored;
        }

        try {
            $client = Client::forAgent($agent, $integration);
            $company = $agent->company;

            if ($client === null || $company === null) {
                return null;
            }

            $workspaceId = $client->ensureWorkspace('Kanvas ' . $company->name);

            if ($workspaceId !== null) {
                $company->set(ConfigurationEnum::WORKSPACE_ID->value, $workspaceId);
            }

            return $workspaceId;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function storedWorkspace(Agent $agent): ?string
    {
        $stored = $agent->company?->get(ConfigurationEnum::WORKSPACE_ID->value);

        return is_string($stored) && trim($stored) !== '' ? trim($stored) : null;
    }
}
