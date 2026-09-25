<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\OpenCode\Actions\ProvisionCodingSessionAction;
use Kanvas\Connectors\OpenCode\Actions\StopCodingSessionRuntimeAction;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;
use Kanvas\Connectors\OpenCode\Services\CodingModelResolver;
use Kanvas\Connectors\OpenCode\Services\CodingPolicy;
use Kanvas\Connectors\OpenCode\Services\RepoAllowListService;
use Kanvas\Connectors\OpenCode\Services\SessionContextBuilder;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPrompt;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\LaunchTaskSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\PollHarnessSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\CreateAgentRunPlanAction;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Turns a coding brief into a Plan + Task (the durable record) plus a session row (the runtime state),
 * provisions somewhere to run, opens the harness session and hands off to the poller.
 *
 * The Plan/Task shape deliberately matches the pi.dev and Claude dispatchers — a coding job looks the
 * same on the board whichever backend runs it.
 */
class DispatchHarnessTaskAction
{
    public const string PLAN_TYPE = 'coding_job';

    private const int DEFAULT_MAX_CONCURRENT = 2;

    public function __construct(
        private readonly Agent $agent,
        private readonly string $task,
        private readonly ?string $repoSlug = null,
        private readonly ?Users $requestedBy = null,
        private readonly HarnessEnum $harness = HarnessEnum::OPENCODE,
        private readonly ?Session $session = null,
        /**
         * The session this one continues. Its branch, repository and pull request carry over, so a
         * follow-up lands on the work already pushed instead of branching off the base again and
         * quietly reverting it.
         */
        private readonly ?AgentTaskSession $continues = null,
    ) {
    }

    public function execute(): Task
    {
        $brief = trim($this->task);

        if ($brief === '') {
            throw new ValidationException('A coding task needs a description');
        }

        $this->assertCapacity();

        $repository = $this->resolveRepository();
        $task = $this->recordAsTask($brief);
        $session = $this->newSession($task);

        if ($this->launchesItsOwnContainer()) {
            // Provisioning here would mean a `git clone` inside the caller's turn.
            LaunchTaskSessionJob::dispatch(
                $this->agent->app,
                $session->getId(),
                $brief,
                $this->repoSlug(),
                Str::trimToNull((string) $this->agent->get(AgentCustomFieldEnum::SYSTEM_PROMPT->value)),
            );

            return $task;
        }

        $this->startNow($session, $brief, $repository);

        PollHarnessSessionJob::dispatch($this->agent->app, $session->getId());

        return $task;
    }

    /**
     * A tenant cap counted on the session table rather than on `plan_type`, which is an unindexed free
     * string. Refuses rather than queueing silently: a coding job that starts an hour later, after the
     * repository moved on, is worse than one that never started.
     */
    private function assertCapacity(): void
    {
        $app = $this->agent->app;
        $company = $this->agent->company;

        $limit = (int) ($company->get(ConfigurationEnum::MAX_CONCURRENT_SESSIONS->value)
            ?? $app->get(ConfigurationEnum::MAX_CONCURRENT_SESSIONS->value)
            ?? self::DEFAULT_MAX_CONCURRENT);

        if ($limit <= 0) {
            return;
        }

        $live = AgentTaskSession::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->live()
            ->count();

        if ($live >= $limit) {
            throw new ValidationException(
                'This company already has ' . $live . ' coding session(s) running (limit ' . $limit . ').'
            );
        }
    }

    private function recordAsTask(string $brief): Task
    {
        return new CreateAgentRunPlanAction(
            agent: $this->agent,
            brief: $brief,
            planType: self::PLAN_TYPE,
            requestedBy: $this->requestedBy,
            input: array_filter([
                'repo_slug' => $this->repoSlug(),
                'harness' => $this->harness->value,
            ]),
            // Carries where this was asked for, so the outcome is reported back into that conversation
            // rather than only onto a plan board nobody is subscribed to.
            session: $this->session,
        )->execute();
    }

    private function newSession(Task $task): AgentTaskSession
    {
        $app = $this->agent->app;
        $company = $this->agent->company;

        $session = new AgentTaskSession();
        $session->apps_id = $app->getId();
        $session->companies_id = $company->getId();
        $session->users_id = ($this->requestedBy ?? $this->agent->user)?->getId();
        $session->agent_id = $this->agent->getId();
        $session->plan_id = $task->plan_id;
        $session->task_id = $task->getId();
        $session->harness = $this->harness->value;
        $session->repo_slug = $this->repoSlug();
        $session->status = HarnessStatusEnum::STARTING->value;
        $session->provider = Str::trimToNull((string) $app->get(ConfigurationEnum::PROVIDER_ID->value));
        $session->model = new CodingModelResolver($app, $this->agent)->model();

        if ($this->continues !== null) {
            $session->branch = $this->continues->branch;
            $session->pull_request_url = $this->continues->pull_request_url;
            $session->continues_session_id = $this->continues->getId();
        }
        $session->credential_source = $company->get(ConfigurationEnum::PROVIDER_API_KEY->value) !== null
            ? 'company_byok'
            : 'kanvas_managed';
        // Stamped here, not in the provisioner: a row that dies before provisioning would otherwise
        // carry NULL timestamps, and the sweeper's `heartbeat_at < ?` can never match a NULL — so it
        // would hold one of the tenant's concurrency slots for good.
        $session->started_at = Carbon::now();
        $session->touchHeartbeat();
        $session->saveOrFail();

        return $session;
    }

    /**
     * Attach mode creates nothing, so there is nothing to wait for and the caller gets a live session
     * back immediately. A machine-backed launch clones a repository first, which does not belong in a
     * tool call.
     */
    private function launchesItsOwnContainer(): bool
    {
        return Str::trimToNull((string) $this->agent->app->get(ConfigurationEnum::STATIC_ENDPOINT->value)) === null;
    }

    private function startNow(AgentTaskSession $session, string $brief, ?CodingRepository $repository): void
    {
        try {
            new ProvisionCodingSessionAction($session, $this->agent->app, $this->agent->company, $repository)
                ->execute();
            HarnessFactory::forSession($session)->start($session, $this->buildPrompt($brief, $repository));
        } catch (Throwable $e) {
            $session->status = HarnessStatusEnum::FAILED->value;
            $session->error_message = $e->getMessage();
            $session->saveOrFail();

            // A launch that got as far as `docker run` and then failed leaves a container nothing else
            // will ever look for: this path never reaches the finalizer.
            new StopCodingSessionRuntimeAction($session)->execute();

            throw $e;
        }
    }

    /**
     * The LLM only ever names a slug; the URL comes from the agent's own allow-list. A free-typed clone
     * URL would let a prompt-injected agent aim a checkout — and later a push — at any repository.
     */
    private function resolveRepository(): ?CodingRepository
    {
        $slug = $this->repoSlug();

        if ($slug === null) {
            return null;
        }

        return new RepoAllowListService($this->agent)->resolveOrFail($slug);
    }

    /**
     * A follow-up is about the same repository as the work it continues, so nobody should have to name
     * it twice — and naming a different one would put the new commits on a branch that belongs to
     * another repository's pull request.
     */
    private function repoSlug(): ?string
    {
        return $this->repoSlug ?? $this->continues?->repo_slug;
    }

    /**
     * In launch mode the identity and the prior handoffs are written into the workspace as files, which
     * survive compaction. Attach mode has no workspace Kanvas controls, so the same content rides in the
     * prompt instead — one builder, two delivery routes, rather than two versions of the memory.
     */
    private function buildPrompt(string $brief, ?CodingRepository $repository = null): HarnessPrompt
    {
        $context = $this->launchesItsOwnContainer()
            ? null
            : new SessionContextBuilder($this->agent, $repository)->contextDocument();

        return new HarnessPrompt(
            task: 'TASK:' . "\n" . $brief,
            policy: CodingPolicy::BLOCK,
            repoRules: $repository?->rules,
            persona: Str::trimToNull((string) $this->agent->get(AgentCustomFieldEnum::SYSTEM_PROMPT->value)),
            memories: $context,
        );
    }
}
