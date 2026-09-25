<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\Actions\FinalizeHarnessSessionAction;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
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
use Throwable;

/**
 * Stops a running coding job.
 *
 * The counterpart to steering: a steer corrects a run worth finishing, this ends one that is not —
 * going the wrong way, taking too long, or superseded by something the person has just said.
 *
 * **Work already done is kept.** The branch and the workspace survive, so a cancelled job that got
 * halfway is still reviewable and still pushable. Cancelling is "stop spending on this", never "throw
 * away what it did".
 */
#[AgentTool(name: 'Cancel Self-Hosted Coding Job', category: 'coding')]
class CancelHarnessCodingJobTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'cancel_self_hosted_coding_job',
            description: 'Stop a coding job that is still running. Use it when the job is going the wrong '
                . 'way, has been superseded, or is clearly stuck. Anything it already changed is kept and '
                . 'can still be reviewed — this stops the work, it does not undo it. A job that has already '
                . 'finished cannot be cancelled.',
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
                description: 'The job id returned when the task was dispatched.',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why it is being stopped, in one sentence. Recorded against the job.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $job_id, ?string $reason = null): array
    {
        $session = AgentTaskSession::forAgentJob($this->agent, $job_id);

        if ($session === null) {
            return $this->notFound(
                'No coding job ' . $job_id . ' for this agent.',
                guidance: 'Use list_self_hosted_coding_jobs to find the right id.'
            );
        }

        if (! $session->isLive()) {
            return $this->noop(
                'Job ' . $job_id . ' already finished as "' . (string) $session->status . '".',
                guidance: 'Nothing was stopped because nothing was running. Report its actual state.'
            );
        }

        try {
            // Interrupt first, so the runtime stops mid-turn rather than being finalised underneath a
            // turn that is still writing files.
            HarnessFactory::forSession($session)->stop($session);
        } catch (Throwable $e) {
            // An unreachable runtime is often WHY someone is cancelling. Record the cancellation anyway.
            report($e);
        }

        new FinalizeHarnessSessionAction(
            $session,
            failureReason: $reason !== null && trim($reason) !== ''
                ? 'Cancelled: ' . trim($reason)
                : 'Cancelled before it finished.'
        )->execute();

        return $this->ok(
            ['job_id' => $job_id, 'branch' => $session->branch],
            guidance: 'The job is stopped. Anything it had already changed is still on its branch — say '
                . 'so, and do not describe the work as undone.'
        );
    }
}
