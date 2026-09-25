<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Answered entirely from the session row — no call reaches the running container, so a supervisor can
 * check as often as it likes without spending a turn of the coding agent's budget.
 */
#[AgentTool(name: 'Check Self-Hosted Coding Job', category: 'coding')]
class CheckHarnessCodingJobTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    // Checking two different jobs in one turn is normal; sharing a budget across them is not.
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'check_self_hosted_coding_job',
            description: 'Check a coding job you started with dispatch_self_hosted_coding_task: whether it is '
                . 'running, waiting on a human, finished or failed, plus elapsed time, tokens and cost. Call at '
                . 'most once per turn — the job advances between turns, not within one.',
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

        return $this->ok([
            'job_id' => $job_id,
            'job_status' => $session->status,
            'waiting_on_a_human' => $session->harnessStatus()->isWaitingOnAHuman(),
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
        ]);
    }
}
