<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Coding;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Check Coding Job Status', category: 'coding')]
class CheckCodingJobStatusTool extends Tool
{
    use ResolvesTaskForTool;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'check_coding_job_status',
            description: 'Check the progress of a coding task you dispatched, using the job id returned by '
                . 'dispatch_coding_task. Tells you whether it is queued, running, or finished, and — when done — '
                . 'the result summary and the pull-request URL if one was opened.',
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
                description: 'The job id returned by dispatch_coding_task.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $job_id): array
    {
        $result = $this->resolveTaskOrError($job_id, "Coding job {$job_id} was not found.");
        if (is_array($result)) {
            return ['status' => 'error', 'message' => $result['error']];
        }

        if ($result->agent_id !== $this->agent->getId()) {
            return ['status' => 'error', 'message' => "Coding job {$job_id} was not found for this agent."];
        }

        $finished = TaskStatusEnum::tryFrom($result->status)?->isTerminal() ?? false;

        return [
            'status' => 'success',
            'job_id' => $result->getId(),
            'finished' => $finished,
            'task_status' => $result->status,
            'pidev_status' => $result->get(TaskCustomFieldEnum::PIDEV_STATUS->value),
            'repo' => $result->get(TaskCustomFieldEnum::PIDEV_REPO_SLUG->value),
            'pull_request_url' => $result->get(TaskCustomFieldEnum::PIDEV_PULL_REQUEST_URL->value),
            'result' => $result->result,
            'blocked_reason' => $result->blocked_reason,
            // Steer the model off a polling loop: the job runs in the background and its status only
            // advances between turns (a worker polls pi.dev every ~30s), so re-checking in the same
            // turn never changes anything and will hit the tool-call limit.
            'note' => $finished
                ? 'The job has finished. Report the result and the pull_request_url (or blocked_reason) to the user now.'
                : 'Still running in the background. STOP checking now — do NOT call this tool again in this turn. Tell the user it is in progress and to ask again in a little while.',
        ];
    }
}
