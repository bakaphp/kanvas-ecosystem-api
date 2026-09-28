<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Coding;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PiDev\Actions\RetryCodingJobAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Retry Coding Job', category: 'coding')]
class RetryCodingJobTool extends Tool
{
    use ResolvesTaskForTool;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'retry_coding_job',
            description: 'Re-run a coding job that failed for a reason outside the work itself — the coding '
                . 'service was rate limited, hit a provider outage, or was interrupted. It re-sends the exact same '
                . 'task under the same job id, so the original plan continues instead of a duplicate being created. '
                . 'Use this instead of dispatch_coding_task when a job you already sent came back blocked and the '
                . 'reason looks temporary. Do NOT use it when the job failed because the task was wrong, impossible, '
                . 'or ambiguous — re-running would only repeat it; fix the description and dispatch a new task instead.',
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

        try {
            new RetryCodingJobAction($result)->execute();
        } catch (ValidationException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Could not queue the retry right now. Tell the user the job is still failed.',
            ];
        }

        return [
            'status' => 'success',
            'job_id' => $result->getId(),
            'task_status' => $result->status,
            'note' => 'The job was re-sent under the same job id and runs in the background. STOP here — do not '
                . 'check its status in this turn; tell the user it is retrying and to ask again in a little while.',
        ];
    }
}
