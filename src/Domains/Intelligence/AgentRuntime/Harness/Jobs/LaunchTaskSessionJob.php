<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Actions\ProvisionCodingSessionAction;
use Kanvas\Connectors\OpenCode\Actions\StopCodingSessionRuntimeAction;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Services\CodingPolicy;
use Kanvas\Connectors\OpenCode\Services\RepoAllowListService;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPrompt;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Throwable;

/**
 * Provisions a session and starts its first turn, off the request path.
 *
 * Only the launch path goes through here. Cloning a repository of any size takes minutes, and doing
 * that inside a tool call means the agent's turn sits waiting on a `git clone` — attach mode, which
 * creates nothing, stays synchronous because there is nothing to wait for.
 */
class LaunchTaskSessionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly int $sessionId,
        public readonly string $brief,
        public readonly ?string $repoSlug = null,
        public readonly ?string $persona = null,
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->where('id', $this->sessionId)->fromApp($this->app)->first();

        if ($session === null || ! $session->isLive()) {
            return;
        }

        $agent = $session->agent;
        $company = $session->company;

        if ($agent === null || $company === null) {
            $this->fail($session, 'The session lost its agent or company before it could start.');

            return;
        }

        try {
            // Inside the try: resolving asks GitHub whether the token can open the repository, and a
            // refusal there has to land on the session row like any other launch failure.
            $repository = $this->resolveRepository();

            new ProvisionCodingSessionAction($session, $this->app, $company, $repository)->execute();

            // No memories here: the launch path writes them into the workspace as `.kanvas/context.md`,
            // where they survive compaction instead of being spent once in the opening prompt.
            HarnessFactory::forSession($session)->start($session, new HarnessPrompt(
                task: 'TASK:' . "\n" . $this->brief,
                policy: CodingPolicy::BLOCK,
                repoRules: $repository?->rules,
                persona: $this->persona,
            ));
        } catch (Throwable $e) {
            $this->fail($session, $e->getMessage());

            return;
        }

        PollHarnessSessionJob::dispatch($this->app, $session->getId());
    }

    private function resolveRepository(): ?CodingRepository
    {
        if ($this->repoSlug === null) {
            return null;
        }

        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->where('id', $this->sessionId)->first();
        $agent = $session?->agent;

        // Same resolution as the dispatch that created this job — anything else and a repository that
        // resolved a moment ago silently becomes "no repository" here, which reads as a bare workspace.
        return $agent === null ? null : new RepoAllowListService($agent)->resolveOrFail($this->repoSlug);
    }

    /**
     * The failure has to land on the row rather than in an exception, because nothing is waiting on
     * this job's return value — the tool call that started it finished long ago.
     */
    private function fail(AgentTaskSession $session, string $reason): void
    {
        $session->status = HarnessStatusEnum::FAILED->value;
        $session->error_message = $reason;
        $session->saveOrFail();

        new StopCodingSessionRuntimeAction($session)->execute();
    }
}
