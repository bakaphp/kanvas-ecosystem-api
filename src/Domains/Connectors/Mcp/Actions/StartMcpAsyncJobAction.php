<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Jobs\PollMcpAsyncJob;
use Kanvas\Connectors\Mcp\Support\McpToolResult;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Enums\McpAsyncJobStatusEnum;
use Kanvas\NervousSystem\Capability\Models\McpAsyncJob;
use Kanvas\Workflow\Models\Integrations;

/**
 * Takes over a tool call that started a job on the vendor's side and returned before it finished. The
 * agent's turn ends here; PollMcpAsyncJob follows the job and resumes the agent when it is done.
 *
 * Returns null — and the caller hands the model the vendor's own answer — whenever the call is not a
 * job to follow: the tool is not declared async, the job already finished, or there is no conversation
 * to come back to.
 */
class StartMcpAsyncJobAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly Integrations $integration,
        private readonly string $remoteToolName,
        private readonly mixed $content,
        private readonly ?string $sessionUuid,
        private readonly ?int $usersId = null,
    ) {
    }

    public function execute(): ?McpAsyncJob
    {
        $config = McpServerConfig::fromIntegration($this->integration)->asyncJob($this->remoteToolName);
        $payload = McpToolResult::json($this->content);

        if ($config === null || $payload === null || $this->sessionUuid === null) {
            return null;
        }

        $externalId = $config->jobIdFrom($payload);
        $status = $config->statusFrom($payload);

        if ($externalId === null || $config->isDone($status)) {
            return null;
        }

        $job = new McpAsyncJob();
        $job->apps_id = $this->agent->apps_id;
        $job->companies_id = $this->agent->companies_id;
        $job->agents_id = $this->agent->getId();
        $job->integrations_id = $this->integration->getId();
        $job->users_id = $this->usersId;
        $job->session_uuid = $this->sessionUuid;
        $job->start_tool = $this->remoteToolName;
        $job->external_id = $externalId;
        $job->status = McpAsyncJobStatusEnum::RUNNING->value;
        $job->external_status = $status;
        $job->live_url = $config->liveUrlFrom($payload);
        $job->expires_at = Carbon::now()->addSeconds($config->timeoutSeconds);
        $job->saveOrFail();

        $job->emitLedgerEvent('mcp.async_job.started', payload: [
            'start_tool' => $job->start_tool,
            'external_id' => $job->external_id,
            'integrations_id' => $job->integrations_id,
        ]);

        PollMcpAsyncJob::dispatch($this->agent->app, $job);

        return $job;
    }
}
