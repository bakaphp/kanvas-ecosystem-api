<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\Enums\PermissionDecisionEnum;
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
 * Answers a permission the coding runtime is blocked on.
 *
 * Without this a job that asks to run something outside its allow-list waits until the sweeper times it
 * out — the runtime is holding the turn open for an answer nothing can give. That is a deadlock, not a
 * safety feature, and it gets worse the tighter the shell rules are.
 *
 * **Relay, do not decide.** `once` and `reject` are judgements about a specific command a person can
 * see; `always` widens what this session may do for the rest of its life, so it is only ever correct
 * when a human said so. The tool enforces that rather than trusting the model to remember it.
 */
#[AgentTool(name: 'Answer Self-Hosted Coding Permission', category: 'coding')]
class AnswerHarnessCodingPermissionTool extends Tool implements HasRunKey
{
    use ReportsToolOutcome;
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
    ) {
        parent::__construct(
            name: 'answer_self_hosted_coding_permission',
            description: 'Answer a coding job that is waiting on a permission — it wants to run something '
                . 'its rules do not already allow. check_self_hosted_coding_job reports the request and its '
                . 'id. Decisions: "once" allows just this, "reject" refuses it, "always" allows anything '
                . 'like it for the rest of the job. Use "always" ONLY when a human in this conversation '
                . 'said so; otherwise ask them, or answer "once".',
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
                description: 'The job that is waiting.',
                required: true,
            ),
            new ToolProperty(
                name: 'permission_id',
                type: PropertyType::STRING,
                description: 'The id of the pending permission, as reported by check_self_hosted_coding_job.',
                required: true,
            ),
            new ToolProperty(
                name: 'decision',
                type: PropertyType::STRING,
                description: 'once, reject, or always.',
                required: true,
            ),
            new ToolProperty(
                name: 'relaying_human_instruction',
                type: PropertyType::BOOLEAN,
                description: 'True only when a human in this conversation just told you how to answer. '
                    . 'Required for "always".',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $job_id,
        string $permission_id,
        string $decision,
        ?bool $relaying_human_instruction = null
    ): array {
        $choice = PermissionDecisionEnum::tryFrom(mb_strtolower(trim($decision)));

        if ($choice === null) {
            return $this->invalidArgs(
                'Decision must be once, reject or always — got "' . $decision . '".',
                guidance: 'Answer again with one of those three.'
            );
        }

        if ($choice === PermissionDecisionEnum::ALWAYS && $relaying_human_instruction !== true) {
            return $this->denied(
                'Only a human can widen a job\'s permissions for the rest of its run.',
                guidance: 'Ask the person whether to allow this permanently. If they only want this one '
                    . 'command to go ahead, answer "once" instead.'
            );
        }

        $session = AgentTaskSession::forAgentJob($this->agent, $job_id);

        if ($session === null || ! $session->isLive()) {
            return $this->notFound(
                'No running coding job ' . $job_id . ' for this agent.',
                guidance: 'A job that already ended cannot be answered. Check its state first.'
            );
        }

        try {
            HarnessFactory::forSession($session)->answerPermission($session, $permission_id, $choice);
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                $e->getMessage(),
                guidance: 'The permission was not answered, so the job is still waiting. Say so.'
            );
        }

        return $this->ok(
            ['job_id' => $job_id, 'decision' => $choice->value],
            guidance: 'Answered. The job carries on from where it stopped; check it again on a later turn.'
        );
    }
}
