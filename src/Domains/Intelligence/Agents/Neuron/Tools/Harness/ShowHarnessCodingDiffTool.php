<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingHarness;
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
 * The actual diff a job produced, read from the runtime.
 *
 * This exists because an agent asked "show me the diff" will otherwise write one. It happened: an agent
 * reported a file edited, a patch "staged", and offered to display it — with no job dispatched and the
 * file untouched on disk. A described change is a claim; this returns the artefact, and a claim with no
 * job behind it has nowhere to come from.
 */
#[AgentTool(name: 'Show Self-Hosted Coding Diff', category: 'coding')]
class ShowHarnessCodingDiffTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use TrackByInputs;

    private const int MAX_PATCH_CHARS = 20000;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'show_self_hosted_coding_diff',
            description: 'Return the real diff a coding job produced, read from the workspace it ran in. '
                . 'Use this whenever anyone asks what changed, or before describing a change. NEVER write '
                . 'out a diff yourself: if this tool returns nothing, nothing was changed.',
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
                description: 'The job id whose diff you want.',
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
                guidance: "There is no coding job {$job_id} of yours. Do not describe changes for it."
            );
        }

        try {
            $harness = HarnessFactory::forSession($session);

            if (! $harness instanceof CodingHarness) {
                return $this->failed('That runtime cannot report a diff.', ['job_id' => $job_id]);
            }

            $diff = $harness->diff($session);
            $patch = $harness->patch($session);
        } catch (Throwable $e) {
            return $this->failed(
                $e->getMessage(),
                ['job_id' => $job_id],
                'The workspace could not be read, so you do NOT know what changed. Say that.'
            );
        }

        if ($diff->isEmpty()) {
            return $this->noop(
                ['job_id' => $job_id, 'files' => []],
                guidance: 'Nothing was changed in that workspace. Say so plainly — do not describe edits.'
            );
        }

        return $this->ok([
            'job_id' => $job_id,
            'summary' => $diff->summary(),
            'files' => $diff->paths(),
            // Truncated rather than summarised: a shortened patch is still evidence, a paraphrased one
            // is back to being the agent's account of it.
            'patch' => mb_substr($patch, 0, self::MAX_PATCH_CHARS),
            'patch_truncated' => mb_strlen($patch) > self::MAX_PATCH_CHARS,
        ]);
    }
}
