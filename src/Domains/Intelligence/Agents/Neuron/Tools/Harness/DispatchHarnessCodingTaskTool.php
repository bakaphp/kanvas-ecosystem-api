<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\Actions\DispatchHarnessTaskAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Dispatch Self-Hosted Coding Task', category: 'coding')]
class DispatchHarnessCodingTaskTool extends Tool implements HasRunKey, RequiresSystemAgent
{
    use ReportsToolOutcome;
    // Distinct briefs are distinct work; without this every dispatch in a turn shares one budget.
    use TrackByInputs;

    public function __construct(
        private readonly Agent $agent,
        private readonly ?Session $session = null,
        private readonly ?Users $requestedBy = null,
    ) {
        parent::__construct(
            name: 'dispatch_self_hosted_coding_task',
            description: 'Start a coding task on the coding runtime Kanvas hosts itself. It checks the '
                . 'repository out itself, so name whichever one the person asked for. The task runs in the '
                . 'background and this returns a job id immediately — it does NOT wait for the work. Neither '
                . 'you nor the coding agent can push; a human approves that once the work is done. Write the '
                . 'task as a complete, self-contained instruction, because the coding agent cannot ask you '
                . 'follow-up questions mid-run.',
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
                name: 'repository',
                type: PropertyType::STRING,
                description: 'Which repository to work on: its slug, its clone URL, or owner/name — '
                    . 'whichever the person gave you, passed through exactly as they wrote it. Any '
                    . 'repository your git token can open will work. Ask rather than guess.',
                required: false,
            ),
            new ToolProperty(
                name: 'task',
                type: PropertyType::STRING,
                description: 'Everything the coding agent needs: what to change, where, acceptance criteria '
                    . 'and any constraints.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $task, ?string $repository = null): array
    {
        try {
            $record = new DispatchHarnessTaskAction(
                agent: $this->agent,
                task: $task,
                repoSlug: $repository,
                requestedBy: $this->requestedBy,
                session: $this->session,
            )->execute();
        } catch (Throwable $e) {
            // Spelling out the wrong move, because the model reliably finds it: told it cannot touch a
            // repository, it offers to write the file contents in the conversation instead. That reads
            // as helpfulness and is the opposite — nobody asked for text, the work did not happen, and
            // a person now has to notice that before pasting it somewhere by hand.
            return $this->failed(
                $e->getMessage(),
                guidance: 'No coding job was started and nothing was changed. Report the reason as '
                    . 'given — a repository your token cannot open usually means a typo, or a token '
                    . 'that does not cover it. Do NOT write the code, the file contents or a diff in '
                    . 'the conversation as a substitute; that is not the work you were asked to do.'
            );
        }

        /** @var AgentTaskSession|null $session */
        $session = AgentTaskSession::query()->forTask($record->getId())->latest('id')->first();

        return $this->ok(
            [
                'job_id' => $record->getId(),
                'session' => $session?->uuid,
            ],
            guidance: 'The job is running in the background. Use check_self_hosted_coding_job with this '
                . 'job_id; it advances between turns, so checking twice in one turn tells you nothing new.'
        );
    }
}
