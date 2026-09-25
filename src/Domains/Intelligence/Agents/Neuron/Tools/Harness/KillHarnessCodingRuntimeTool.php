<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Connectors\OpenCode\Actions\KillAgentCodingContainerAction;
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
 * Destroys the agent's coding container.
 *
 * Distinct from cancelling a job: cancelling stops one piece of work, this removes the runtime that
 * every job on this agent shares. It is for a container that has stopped answering, where cancelling
 * would go nowhere because the thing being asked is the thing that is broken.
 *
 * Requires a human to have asked. The blast radius is every job currently running on this agent, and
 * that is not a judgement a model should reach on its own from a job looking slow — `cancel` is the
 * tool for that.
 */
#[AgentTool(name: 'Kill Self-Hosted Coding Runtime', category: 'coding')]
class KillHarnessCodingRuntimeTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'kill_self_hosted_coding_runtime',
            description: 'Destroy this agent\'s coding container — the shared runtime every one of its '
                . 'jobs runs inside. Use it only when the runtime itself is broken: jobs not responding, '
                . 'checks timing out, the container wedged. It kills EVERY job currently running on this '
                . 'agent, so to stop one job use cancel_self_hosted_coding_job instead. Committed work, '
                . 'branches and workspaces are on the host and survive. The next task starts a fresh '
                . 'container automatically. Only call this when a human has asked for it.',
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
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why the runtime is being killed, in one sentence. Recorded against every '
                    . 'job it stops.',
                required: true,
            ),
            new ToolProperty(
                name: 'relaying_human_instruction',
                type: PropertyType::BOOLEAN,
                description: 'True only when a human in this conversation just asked for the runtime to '
                    . 'be killed or restarted.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $reason, bool $relaying_human_instruction): array
    {
        if ($relaying_human_instruction !== true) {
            return $this->denied(
                'Killing the runtime stops every job on this agent, so a human has to ask for it.',
                guidance: 'If one job is the problem, use cancel_self_hosted_coding_job. Otherwise ask '
                    . 'the person whether to kill the runtime, and call this again once they say yes.'
            );
        }

        try {
            $result = new KillAgentCodingContainerAction($this->agent)->execute(trim($reason));
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                $e->getMessage(),
                guidance: 'The runtime was not killed. Report the reason as given.'
            );
        }

        return $this->ok(
            $result,
            guidance: 'The container is gone and any jobs inside it are closed as failed. The next task '
                . 'dispatched starts a fresh one — say that, and say how many jobs were stopped.'
        );
    }
}
