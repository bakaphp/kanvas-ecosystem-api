<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Actions;

use Kanvas\Connectors\Mcp\DataTransferObject\McpAsyncJobConfig;
use Kanvas\Connectors\Mcp\Exceptions\McpFetchException;
use Kanvas\Connectors\Mcp\Jobs\ResumeAgentFromMcpAsyncJob;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Support\McpToolResult;
use Kanvas\NervousSystem\Capability\Enums\McpAsyncJobStatusEnum;
use Kanvas\NervousSystem\Capability\Models\McpAsyncJob;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Scheduling\Actions\DeliverScheduledMessageToChannelAction;
use NeuronAI\MCP\McpTransportInterface;
use Throwable;

/**
 * One check on a running job. $transport is a test seam; production always uses the guarded transport.
 */
class FollowMcpAsyncJobAction
{
    /** A vendor blip is retried; this many failed checks in a row is an outage worth telling the user. */
    public const int MAX_CONSECUTIVE_ERRORS = 5;

    public function __construct(
        private readonly McpAsyncJob $job,
        private readonly ?McpTransportInterface $transport = null,
    ) {
    }

    public function execute(): McpAsyncJob
    {
        if (! $this->job->isRunning()) {
            return $this->job;
        }

        if ($this->job->agent === null || $this->job->integration === null) {
            return $this->end(McpAsyncJobStatusEnum::FAILED, error: 'the agent or its MCP server no longer exists');
        }

        $config = McpAsyncJobConfig::forJob($this->job->integration, $this->job->start_tool);

        if ($config === null) {
            return $this->end(McpAsyncJobStatusEnum::FAILED, error: 'the server no longer declares this tool as a background job');
        }

        try {
            $payload = $this->checkStatus($config);
        } catch (Throwable $e) {
            return $this->recordFailedCheck($e);
        }

        $this->job->poll_count++;
        $this->job->consecutive_errors = 0;
        $this->job->external_status = $config->statusFrom($payload);
        $this->job->live_url = $config->liveUrlFrom($payload) ?? $this->job->live_url;
        $this->job->saveOrFail();

        if ($config->isDone($this->job->external_status)) {
            $this->storeArtifacts();

            return $this->end(McpAsyncJobStatusEnum::COMPLETED, result: $this->encode($payload));
        }

        if ($this->job->isExpired()) {
            return $this->end(McpAsyncJobStatusEnum::TIMED_OUT);
        }

        // A short task is already over on the first check, and a live link to a dead session is worse
        // than none.
        $this->postLiveUrl();

        return $this->job;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkStatus(McpAsyncJobConfig $config): array
    {
        $content = new McpConnectionService($this->job->agent, $this->job->integration, $this->transport)
            ->connector()
            ->callRemoteTool($config->statusTool, [$config->idField => $this->job->external_id]);

        return McpToolResult::json($content)
            ?? throw new McpFetchException(sprintf('`%s` did not answer with a JSON status.', $config->statusTool));
    }

    private function recordFailedCheck(Throwable $e): McpAsyncJob
    {
        $this->job->poll_count++;
        $this->job->consecutive_errors++;
        $this->job->last_error = mb_substr($e->getMessage(), 0, 2000);

        if ($this->job->consecutive_errors >= self::MAX_CONSECUTIVE_ERRORS) {
            return $this->end(McpAsyncJobStatusEnum::FAILED, error: $e->getMessage());
        }

        $this->job->saveOrFail();

        return $this->job;
    }

    /**
     * Posted once per distinct URL. A session with no channel has nowhere to post, so the URL is marked
     * seen rather than retried on every check.
     */
    private function postLiveUrl(): void
    {
        if (! $this->job->hasUnpostedLiveUrl()) {
            return;
        }

        $session = $this->job->resolveSession();
        $author = $this->job->agent?->user;

        if ($session?->channel !== null && $author !== null) {
            try {
                new DeliverScheduledMessageToChannelAction(
                    channel: $session->channel,
                    text: "Live browser for the task I'm running — open it to watch, or to log in if it asks you to:\n"
                        . $this->job->live_url,
                    author: $author,
                    agent: $this->job->agent,
                    sessionUuid: $session->uuid,
                    canalId: $session->canal_id,
                    verb: 'mcp-job-live-url',
                )->execute();
            } catch (Throwable $e) {
                // Failing to show the link must not stop the job being followed to its result.
                report($e);
            }
        }

        $this->job->live_url_posted = $this->job->live_url;
        $this->job->saveOrFail();
    }

    /** These download links last a minute, so the files are pulled in here, not handed to the agent. */
    private function storeArtifacts(): void
    {
        $artifacts = AttachMcpJobArtifactsToPlanAction::collectFor($this->job);

        if ($artifacts === []) {
            return;
        }

        try {
            $plan = new AttachMcpJobArtifactsToPlanAction($this->job, $artifacts)->execute();
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($plan === null) {
            return;
        }

        // The plan's own files, not what the vendor offered: a failed download would otherwise be
        // announced as saved.
        $this->job->artifacts = [
            'plan_id' => $plan->getId(),
            'files' => $plan->getFiles()->pluck('name')->all(),
        ];
        $this->job->saveOrFail();
    }

    private function end(McpAsyncJobStatusEnum $status, ?string $result = null, ?string $error = null): McpAsyncJob
    {
        $this->job->finish($status, result: $result, error: $error);

        $this->job->emitLedgerEvent(
            'mcp.async_job.' . $status->value,
            status: $status === McpAsyncJobStatusEnum::COMPLETED ? EventStatusEnum::INFO : EventStatusEnum::ERROR,
            payload: [
                'start_tool' => $this->job->start_tool,
                'external_id' => $this->job->external_id,
                'external_status' => $this->job->external_status,
                'poll_count' => $this->job->poll_count,
            ],
        );

        $app = $this->job->agent?->app;

        if ($app !== null) {
            ResumeAgentFromMcpAsyncJob::dispatch($app, $this->job);
        }

        return $this->job;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
