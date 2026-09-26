<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\Actions\DispatchHarnessTaskAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Carries on a finished job — same branch, same pull request.
 *
 * Without this every task starts from the base branch, so "address the review" would check out the
 * original code, make the requested change, and push a branch missing everything the first pass did.
 * The reviewer would watch their feedback arrive as a revert.
 *
 * A continuation reuses the branch, so the existing pull request simply gains commits. That is the loop
 * that makes this a teammate rather than a one-shot generator: propose, get told, adjust, in one review.
 */
#[AgentTool(name: 'Continue Self-Hosted Coding Job', category: 'coding')]
class ContinueHarnessCodingJobTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
        private readonly ?Session $session = null,
        private readonly ?Users $requestedBy = null,
    ) {
        parent::__construct(
            name: 'continue_self_hosted_coding_job',
            description: 'Do more work on a job that already finished, on the SAME branch and the same '
                . 'pull request. Use this for review feedback, a follow-up fix, or anything building on '
                . 'work already pushed — never dispatch a fresh task for that, because a fresh task '
                . 'starts from the base branch and would undo it. Describe only the new change; the '
                . 'earlier work is already there.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'job_id',
                type: PropertyType::INTEGER,
                description: 'The finished job whose branch this continues.',
                required: true,
            ),
            new ToolProperty(
                name: 'task',
                type: PropertyType::STRING,
                description: 'What to change now. Complete and self-contained, like any other brief — but '
                    . 'about the ADJUSTMENT, not a restatement of the original task.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $job_id, string $task): array
    {
        $previous = AgentTaskSession::forAgentJob($this->agent, $job_id);

        if ($previous === null) {
            return $this->notFound(
                ['job_id' => $job_id],
                'No coding job ' . $job_id . ' for this agent. Use list_self_hosted_coding_jobs to find '
                    . 'the right id.'
            );
        }

        if ($previous->branch === null) {
            return $this->invalidArgs(
                'Job ' . $job_id . ' has no branch, so there is nothing to continue.',
                guidance: 'Dispatch it as a new task instead.'
            );
        }

        if ($previous->isLive()) {
            return $this->invalidArgs(
                'Job ' . $job_id . ' is still running.',
                guidance: 'Steer it with steer_self_hosted_coding_job instead of continuing it — two '
                    . 'sessions writing the same branch would collide.'
            );
        }

        try {
            $record = new DispatchHarnessTaskAction(
                agent: $this->agent,
                task: $task,
                requestedBy: $this->requestedBy,
                session: $this->session,
                continues: $previous,
            )->execute();
        } catch (Throwable $e) {
            return $this->failed(
                $e->getMessage(),
                guidance: 'Nothing was started and the branch is untouched.'
            );
        }

        return $this->ok(
            [
                'job_id' => $record->getId(),
                'continues' => $job_id,
                'branch' => $previous->branch,
                'pull_request' => $previous->pull_request_url,
            ],
            guidance: 'Running in the background on the existing branch. When it finishes the same pull '
                . 'request gains the new commits — say that, rather than describing a new PR.'
        );
    }
}
