<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Coding;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PiDev\Actions\CancelCodingJobAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Cancel Coding Job')]
class CancelCodingJobTool extends Tool
{
    use ResolvesTaskForTool;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'cancel_coding_job',
            description: 'Signal cancellation of a coding task you dispatched, using its job id. Cancellation is '
                . 'best-effort and asynchronous — anything the coding agent already pushed stays pushed. Check the '
                . 'job status afterwards to confirm it stopped.',
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
            new CancelCodingJobAction($result)->execute();
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Could not signal cancellation right now. Tell the user the job may still be running.',
            ];
        }

        return [
            'status' => 'success',
            'job_id' => $result->getId(),
            'note' => 'Cancellation signalled. It may take a moment to stop; use check_coding_job_status to confirm the final state.',
        ];
    }
}
