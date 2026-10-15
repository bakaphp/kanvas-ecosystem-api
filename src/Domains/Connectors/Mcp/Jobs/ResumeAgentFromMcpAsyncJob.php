<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\Chat\WakeAgentInSessionAction;
use Kanvas\NervousSystem\Capability\Models\McpAsyncJob;
use NeuronAI\Exceptions\RunInFlightException;

class ResumeAgentFromMcpAsyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /**
     * Retries exist only for a busy thread, which releases without throwing; any real failure ends the
     * job on the first exception, so a turn that already ran tools is never run twice.
     */
    public int $tries = 3;

    public int $maxExceptions = 1;

    public int $backoff = 60;

    public function __construct(
        public readonly Apps $app,
        public readonly McpAsyncJob $asyncJob,
    ) {
        $this->onQueue('agent-chat');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $agent = $this->asyncJob->agent;
        $session = $this->asyncJob->resolveSession();
        $user = $this->asyncJob->user ?? $agent?->user;

        if ($agent === null || $session === null || $user === null) {
            Log::warning('MCP async job finished with no conversation to resume', [
                'mcp_async_job_id' => $this->asyncJob->getId(),
                'agents_id' => $this->asyncJob->agents_id,
                'session_uuid' => $this->asyncJob->session_uuid,
            ]);

            return;
        }

        try {
            new WakeAgentInSessionAction(
                agent: $agent,
                session: $session,
                instruction: $this->asyncJob->resumeInstruction(),
                user: $user,
                verb: 'mcp-job-reply',
            )->execute();
        } catch (RunInFlightException) {
            $this->release($this->backoff);
        }
    }
}
