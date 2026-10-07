<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Harness;

use Kanvas\Intelligence\AgentRuntime\Harness\Actions\DispatchHarnessTaskAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\SplitsReferenceSlugs;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Dispatch Self-Hosted Coding Task', category: 'coding')]
class DispatchHarnessCodingTaskTool extends Tool implements RequiresSystemAgent
{
    use ReportsToolOutcome;
    use SplitsReferenceSlugs;
    // Distinct briefs are distinct work; without this every dispatch in a turn shares one budget.
    use TrackByInputs;

    protected string $name = 'dispatch_self_hosted_coding_task';

    protected ?string $description = 'Start a coding task on the coding runtime Kanvas hosts itself. It checks the '
        . 'repository out itself, so name whichever one the person asked for. The task runs in the '
        . 'background and this returns a job id immediately — it does NOT wait for the work. Neither '
        . 'you nor the coding agent can push; a human approves that once the work is done. Write the '
        . 'task as a complete, self-contained instruction, because the coding agent cannot ask you '
        . 'follow-up questions mid-run. ONE repository is worked on per job, and the coding '
        . 'agent cannot reach any other by itself — it has no credentials. If it needs to see '
        . 'another repository, name it in `references` and it is checked out beside the work, '
        . 'read-only; for one already-known file, paste the content into the task instead.';

    public function __construct(
        private readonly Agent $agent,
        private readonly ?Session $session = null,
        private readonly ?Users $requestedBy = null,
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
                name: 'repository',
                type: PropertyType::STRING,
                description: 'Which repository to work on: its slug, its clone URL, or owner/name — '
                    . 'whichever the person gave you, passed through exactly as they wrote it. Any '
                    . 'repository your git token can open will work. Ask rather than guess. This is the '
                    . 'ONLY repository the job can see.',
                required: false,
            ),
            new ToolProperty(
                name: 'task',
                type: PropertyType::STRING,
                description: 'Everything the coding agent needs: what to change, where, acceptance criteria '
                    . 'and any constraints. It cannot reach any repository but the one above, so anything '
                    . 'from elsewhere — a document, a file, a diff — belongs pasted in here as text, never '
                    . 'referenced as somewhere to go and fetch.',
                required: true,
            ),
            new ToolProperty(
                name: 'references',
                type: PropertyType::STRING,
                description: 'Other repositories to READ while doing this work, comma separated — for '
                    . 'when the task is "build it the way X does". Each is checked out beside the work '
                    . 'so the coding agent can explore it: grep it, follow a caller, read its history. '
                    . 'Nothing in them is ever edited, committed or pushed. Use this instead of pasting '
                    . 'files into the task when the agent needs to look around rather than copy one '
                    . 'known file.',
                required: false,
            ),
            new ToolProperty(
                name: 'attachments',
                type: PropertyType::STRING,
                description: 'Files to hand the coding agent, as comma-separated filesystem_ids — take them '
                    . 'from the "[Attached file ... filesystem_id: N]" notes on the messages you were sent. '
                    . 'Use it for designs, mockups, screenshots and documents the work has to follow: the '
                    . 'coding agent cannot see anything you only describe in words. The files are placed '
                    . 'beside the repository, never committed. Mention them in the task, e.g. "match the '
                    . 'attached design".',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $task,
        ?string $repository = null,
        ?string $references = null,
        ?string $attachments = null
    ): array {
        try {
            $record = new DispatchHarnessTaskAction(
                agent: $this->agent,
                task: $task,
                repoSlug: $repository,
                requestedBy: $this->requestedBy,
                session: $this->session,
                referenceSlugs: $this->splitReferenceSlugs($references),
                attachmentIds: $this->splitAttachmentIds($attachments),
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

    /**
     * @return list<int>
     */
    private function splitAttachmentIds(?string $attachments): array
    {
        preg_match_all('/\d+/', (string) $attachments, $matches);

        return array_map('intval', $matches[0]);
    }
}
