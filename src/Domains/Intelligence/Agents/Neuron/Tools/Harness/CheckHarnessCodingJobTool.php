<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPermissionRequest;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessQuestion;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Answered from the session row, so a supervisor can check as often as it likes without spending a
 * turn of the coding agent's budget. The one exception is a job parked on a human: what it is waiting
 * on lives only in the runtime, and an agent told it is blocked without being told what by cannot
 * unblock it.
 */
#[AgentTool(name: 'Check Self-Hosted Coding Job', category: 'coding')]
class CheckHarnessCodingJobTool extends Tool implements RequiresSystemAgent
{
    use ReportsToolOutcome;
    // Checking two different jobs in one turn is normal; sharing a budget across them is not.
    use TrackByInputs;

    protected string $name = 'check_self_hosted_coding_job';

    protected ?string $description = 'Check a coding job you started with dispatch_self_hosted_coding_task: whether it is '
        . 'running, waiting on a human, finished or failed, plus elapsed time, tokens and cost. When it '
        . 'is waiting, this also returns pending_permissions — each with the permission_id that '
        . 'answer_self_hosted_coding_permission needs. Call at '
        . 'most once per turn — the job advances between turns, not within one.';

    public function __construct(
        private readonly Agent $agent,
    ) {
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
                description: 'The job id returned by dispatch_self_hosted_coding_task.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $job_id): array
    {
        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::forAgentJob($this->agent, $job_id);

        if ($session === null) {
            return $this->notFound(
                ['job_id' => $job_id],
                guidance: "Coding job {$job_id} does not belong to you. Do not guess another id."
            );
        }

        $waiting = $session->harnessStatus()->isWaitingOnAHuman();

        return $this->ok(
            [
                'job_id' => $job_id,
                'job_status' => $session->status,
                'waiting_on_a_human' => $waiting,
                ...($waiting ? $this->whatItIsWaitingOn($session) : []),
                'elapsed_seconds' => $session->elapsedSeconds(),
                'seconds_since_activity' => $session->secondsSinceHeartbeat(),
                'model' => $session->model,
                'input_tokens' => $session->input_tokens,
                'output_tokens' => $session->output_tokens,
                'estimated_cost_usd' => $session->estimated_cost,
                'branch' => $session->branch,
                'pull_request' => $session->pull_request_url,
                'handoff' => $session->handoff,
                'error' => $session->error_message,
            ],
            guidance: $waiting
                ? 'This job is blocked and will be killed at the session timeout if nobody answers. '
                    . 'Answer each pending_permissions entry with answer_self_hosted_coding_permission '
                    . 'and each pending_questions entry with answer_self_hosted_coding_question, passing '
                    . 'the id it came with. "once" is yours to judge on a specific command, "always" '
                    . 'only if a human in this conversation said so. Never tell the person to click '
                    . 'Approve somewhere; there is no such button, and you hold the tools.'
                : null
        );
    }

    /**
     * The pending permissions, read live from the runtime.
     *
     * The session row records THAT a job is waiting, never what on — so without this the agent learns
     * it is blocked, cannot produce the `permission_id` the answer tool needs, and invents an Approve
     * button for the person to click. The job then dies at the timeout with its work thrown away.
     *
     * Costs one call to the container, and only while a job is actually parked.
     *
     * @return array<string, mixed>
     */
    private function whatItIsWaitingOn(AgentTaskSession $session): array
    {
        try {
            $tick = HarnessFactory::forSession($session)->poll($session);
        } catch (Throwable $e) {
            report($e);

            // Saying so, rather than returning nothing: an empty list and an unreadable runtime look
            // identical to the model, and it reads the first as "nothing to answer" and moves on.
            return ['pending_unreadable' => 'Could not reach the runtime to read what it is waiting on.'];
        }

        return [
            'pending_permissions' => array_map(
                static fn (HarnessPermissionRequest $permission): array => [
                    'permission_id' => $permission->id,
                    'wants_to_run' => $permission->describe(),
                ],
                $tick->permissions
            ),
            'pending_questions' => array_map(
                static fn (HarnessQuestion $question): array => [
                    'question_id' => $question->id,
                    'asks' => $question->describe(),
                ],
                $tick->questions
            ),
        ];
    }
}
