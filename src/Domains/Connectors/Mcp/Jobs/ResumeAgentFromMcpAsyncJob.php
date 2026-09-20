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

/**
 * Wakes the agent in the conversation it started the job from, handing it the outcome.
 */
class ResumeAgentFromMcpAsyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

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

        new WakeAgentInSessionAction(
            agent: $agent,
            session: $session,
            instruction: $this->asyncJob->resumeInstruction(),
            user: $user,
            verb: 'mcp-job-reply',
        )->execute();
    }
}
