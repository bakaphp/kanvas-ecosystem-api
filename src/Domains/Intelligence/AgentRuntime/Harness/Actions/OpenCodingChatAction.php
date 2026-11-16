<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\LaunchStageEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\LaunchCodingChatJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\CreateAgentRunPlanAction;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;

/**
 * Records the chat as a Plan + Task like any coding job (so it shows on the board and its outcome has
 * somewhere to land), then hands the slow part — the factory measures a baseline before it answers —
 * to the `coding` queue.
 */
class OpenCodingChatAction
{
    public const string PLAN_TYPE = 'coding_chat';

    public function __construct(
        private readonly Agent $agent,
        private readonly Session $chat,
        private readonly string $repo,
        private readonly string $instruction,
        private readonly ?Users $requestedBy = null,
        /**
         * Set when a workflow handed this agent a task: the run becomes that task's child, so the
         * task waits on it and its outcome resumes the task — the same edge a delegated coding job uses.
         */
        private readonly ?Task $parentTask = null,
    ) {
    }

    public function execute(): AgentTaskSession
    {
        $machine = HarnessFactory::codingMachineFor($this->agent);

        if ($machine === null) {
            throw new ValidationException(
                'This agent has no coding machine. Set CODING_MACHINE_ID to the factory Mac.'
            );
        }

        $task = new CreateAgentRunPlanAction(
            agent: $this->agent,
            // A task run's instruction is a whole brief; the task it works is what names it on the board.
            brief: $this->parentTask?->title
                ?? ($this->instruction !== '' ? $this->instruction : 'Coding chat in ' . $this->repo),
            planType: self::PLAN_TYPE,
            requestedBy: $this->requestedBy,
            input: ['repo' => $this->repo, 'harness' => HarnessEnum::OPENCODE->value],
            titlePrefix: ($this->parentTask !== null ? 'Factory run (' : 'Coding chat (') . $this->repo . '): ',
            session: $this->chat,
            parentTask: $this->parentTask,
        )->execute();

        $session = new AgentTaskSession();
        $session->apps_id = $this->agent->app->getId();
        $session->companies_id = $this->agent->company->getId();
        $session->users_id = ($this->requestedBy ?? $this->agent->user)?->getId();
        $session->agent_id = $this->agent->getId();
        $session->plan_id = $task->plan_id;
        $session->task_id = $task->getId();
        $session->chat_session_id = $this->chat->getId();
        $session->agent_machine_id = $machine->getId();
        $session->harness = HarnessEnum::OPENCODE->value;
        $session->repo_slug = $this->repo;
        $session->status = HarnessStatusEnum::STARTING->value;
        $session->launch_stage = LaunchStageEnum::PREPARING_WORKTREE->value;
        $session->expects_changes = false;
        $session->credential_source = 'factory';
        $session->started_at = Carbon::now();
        $session->touchHeartbeat();
        $session->saveOrFail();

        LaunchCodingChatJob::dispatch($this->agent->app, $session->getId(), $this->instruction);

        return $session;
    }
}
