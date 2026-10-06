<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

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
 * Answers a question a coding job stopped on.
 *
 * The sibling of {@see AnswerHarnessCodingPermissionTool}, and needed for the same reason: a parked
 * job runs down the session timeout and loses its work. A permission is a yes or no about a command;
 * this is an actual answer the agent asked for — which repository, which column, which of two names.
 *
 * Unlike a permission, the content is a judgement about the work, so relay it rather than invent it:
 * a made-up answer produces a confident diff built on the wrong premise, which is more expensive than
 * the job timing out.
 */
#[AgentTool(name: 'Answer Self-Hosted Coding Question', category: 'coding')]
class AnswerHarnessCodingQuestionTool extends Tool implements RequiresSystemAgent
{
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'answer_self_hosted_coding_question';

    protected ?string $description = 'Answer a coding job that is waiting on a question — it asked something and '
        . 'stopped until it is told. Take the job_id and question_id from '
        . 'check_self_hosted_coding_job. Answer from what you actually know about the work or '
        . 'what a human in this conversation told you; if neither, ask them rather than '
        . 'guessing, because the job builds on whatever you say. A question nobody answers '
        . 'holds the job until it times out and its work is thrown away.';

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
                description: 'The job that is waiting.',
                required: true,
            ),
            new ToolProperty(
                name: 'question_id',
                type: PropertyType::STRING,
                description: 'The id of the pending question, as reported by check_self_hosted_coding_job.',
                required: true,
            ),
            new ToolProperty(
                name: 'answer',
                type: PropertyType::STRING,
                description: 'The answer, as the value it asked for — not a sentence about it.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $job_id, string $question_id, string $answer): array
    {
        if (trim($answer) === '') {
            return $this->invalidArgs(
                'The answer is empty.',
                guidance: 'The job is still waiting. Answer it with a value, or ask the person for one.'
            );
        }

        $session = AgentTaskSession::forAgentJob($this->agent, $job_id);

        if ($session === null || ! $session->isLive()) {
            return $this->notFound(
                ['job_id' => $job_id],
                'No running coding job ' . $job_id . ' for this agent. A job that already ended cannot '
                    . 'be answered. Check its state first.'
            );
        }

        try {
            HarnessFactory::forSession($session)->answerQuestion($session, $question_id, trim($answer));
        } catch (Throwable $e) {
            report($e);

            // A form with several fields cannot be answered from one string, and the runtime says which
            // fields it wanted — so the message is worth relaying rather than summarising.
            return $this->failed(
                $e->getMessage(),
                guidance: 'The question was not answered, so the job is still waiting. Say so, and say '
                    . 'what it asked for.'
            );
        }

        return $this->ok(
            ['job_id' => $job_id, 'question_id' => $question_id],
            guidance: 'Answered. The job carries on from where it stopped; check it again on a later turn.'
        );
    }
}
