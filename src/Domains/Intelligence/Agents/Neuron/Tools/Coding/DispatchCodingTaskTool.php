<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Coding;

use Kanvas\Connectors\PiDev\Actions\DispatchCodingJobAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Dispatch Coding Task', category: 'coding')]
class DispatchCodingTaskTool extends Tool
{
    public function __construct(
        private readonly Agent $agent,
        private readonly ?Users $requestedBy = null,
    ) {
        parent::__construct(
            name: 'dispatch_coding_task',
            description: 'Hand a self-contained programming task to a coding agent that clones one of your '
                . 'pre-approved repositories, does the work on a new branch, and opens a pull request. You do NOT '
                . 'choose the repository URL or provide any credentials — pick a repository by its slug from your '
                . 'allow-list (the GitHub token is supplied for you). The job runs asynchronously; this returns a '
                . 'job id you can pass to check_coding_job_status. Describe the task clearly and completely — the '
                . 'coding agent cannot ask you follow-up questions. '
                . 'Pick this one when the change is self-contained inside a single repository. It works from the '
                . 'repository alone: it cannot call Kanvas tools while it runs, is not graded against acceptance '
                . 'criteria, and returns only a pull request. If the work needs live Kanvas data mid-task, has '
                . 'checkable acceptance criteria, spans more than one repository, or should hand back a produced '
                . 'file, a hosted long-task dispatcher is the better fit where you have one.',
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
                name: 'repo_slug',
                type: PropertyType::STRING,
                description: 'The slug of an allowed repository from your configuration (e.g. "widgets"). Must be one you are allowed to work on.',
                required: true,
            ),
            new ToolProperty(
                name: 'task',
                type: PropertyType::STRING,
                description: 'A clear, complete, self-contained description of what to change, in plain English. The coding agent cannot ask for clarification.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $repo_slug, string $task): array
    {
        $repoSlug = trim($repo_slug);
        $taskText = trim($task);

        if ($repoSlug === '' || $taskText === '') {
            return [
                'status' => 'error',
                'message' => 'Both a repository slug and a task description are required.',
            ];
        }

        try {
            $task = new DispatchCodingJobAction($this->agent, $repoSlug, $taskText, requestedBy: $this->requestedBy)->execute();
        } catch (ValidationException $e) {
            // Unknown repo slug or an agent not configured for pi.dev — actionable, not a crash.
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'error',
                'message' => 'Could not queue the coding task right now. Do not retry immediately; tell the user it failed to start.',
            ];
        }

        return [
            'status' => 'success',
            'job_id' => $task->getId(),
            'task_status' => $task->status,
            'repo' => $repoSlug,
            'note' => 'The coding task is queued and runs in the background as a plan task. Use check_coding_job_status with this job_id to see progress or the pull-request link.',
        ];
    }
}
