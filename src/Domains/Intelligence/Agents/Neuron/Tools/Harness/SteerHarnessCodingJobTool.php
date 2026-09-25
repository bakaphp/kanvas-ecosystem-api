<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

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
 * Corrects a run that is already going.
 *
 * Provenance decides the budget, not who is holding the tool: relaying what a human just said is free,
 * because the human already approved it by saying it; the agent deciding on its own spends one of a
 * small number of interventions. Without that split, "stop, rename it" via the PM would be blocked,
 * which is absurd — and an agent freelancing indefinitely would be unaccountable.
 *
 * A steer does NOT undo work already done. For a change of direction, cancel and dispatch again.
 */
#[AgentTool(name: 'Steer Self-Hosted Coding Job', category: 'coding')]
class SteerHarnessCodingJobTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use TrackByInputs;

    private const int MAX_AGENT_INITIATED_STEERS = 3;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'steer_self_hosted_coding_job',
            description: 'Send a correction to a coding job while it is still running — "also update the '
                . 'tests", "use the existing helper instead". Work already finished is NOT undone. If you are '
                . 'passing on what a human just told you, set relaying_human_instruction to true; if this is '
                . 'your own judgement, leave it false and note you have a small budget of these per job.',
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
                description: 'The job id to steer.',
                required: true,
            ),
            new ToolProperty(
                name: 'message',
                type: PropertyType::STRING,
                description: 'The correction, written as an instruction to the coding agent.',
                required: true,
            ),
            new ToolProperty(
                name: 'relaying_human_instruction',
                type: PropertyType::BOOLEAN,
                description: 'True only when a human in this conversation just asked for this change.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $job_id, string $message, ?bool $relaying_human_instruction = null): array
    {
        $relaying = $relaying_human_instruction ?? false;

        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::forAgentJob($this->agent, $job_id);

        if ($session === null) {
            return $this->notFound(
                ['job_id' => $job_id],
                guidance: "Coding job {$job_id} does not belong to you."
            );
        }

        if (! $session->isLive()) {
            return $this->noop(
                ['job_id' => $job_id],
                guidance: 'That job has already finished, so nothing was sent. Do not try again.'
            );
        }

        if (! $relaying && $session->agent_steer_count >= self::MAX_AGENT_INITIATED_STEERS) {
            return $this->denied(
                'You have used your ' . self::MAX_AGENT_INITIATED_STEERS . ' self-directed corrections on '
                . 'this job.',
                ['job_id' => $job_id],
                guidance: 'Nothing was sent. Ask a human what to do instead of correcting it again.'
            );
        }

        try {
            HarnessFactory::forSession($session)->steer($session, $message);
        } catch (Throwable $e) {
            return $this->failed($e->getMessage(), ['job_id' => $job_id], 'The correction was NOT delivered.');
        }

        if (! $relaying) {
            $session->agent_steer_count++;
            $session->saveOrFail();
        }

        return $this->ok(
            ['job_id' => $job_id],
            guidance: 'Correction sent. Work already completed before it landed is NOT undone.'
        );
    }
}
