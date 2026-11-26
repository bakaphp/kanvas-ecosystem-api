<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingChatSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\CodingChatCheckouts;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Models\Task;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The repositories live on the factory machine, where an agent that runs in Kanvas cannot read them. This
 * puts the question to a factory agent as a read-only sub-task of the asker's task, which waits and
 * resumes with the answer through the ordinary child-outcome path.
 */
#[AgentTool(name: 'Ask Repository', category: 'nervous_system')]
class AskRepositoryTool extends Tool implements RequiresSystemAgent
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesTaskForTool;
    use TrackByInputs;

    protected string $name = 'ask_repository';

    protected ?string $description = 'Ask a question about a code repository (an app, a template, the API). '
        . 'The repositories are on the factory machine and you cannot read them yourself: a factory agent '
        . 'reads the repository and answers, read-only. Use it while working a '
        . 'task, whenever the task needs facts from a repository (what the template already has, which '
        . 'components or services exist, what the specs say). Your task waits and resumes with the answer, '
        . 'so end your turn after asking. Never describe a repository you have not had answered.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'task_id',
                type: PropertyType::INTEGER,
                description: 'The task you are working, which waits for the answer.',
                required: true,
            ),
            new ToolProperty(
                name: 'repository',
                type: PropertyType::STRING,
                description: 'The repository folder, e.g. example-app.',
                required: true,
            ),
            new ToolProperty(
                name: 'question',
                type: PropertyType::STRING,
                description: 'Everything you need answered from it, in one question. Ask once per repository.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $task_id, string $repository, string $question): array
    {
        $task = $this->resolveTaskOrError($task_id, "Task {$task_id} was not found. Use the id of the task you are working.");

        if (is_array($task)) {
            return $task;
        }

        if ($task->isTerminal()) {
            return $this->denied("Task {$task_id} is already {$task->status}, so nothing can wait on an answer.");
        }

        $repository = Str::trimToNull($repository);
        $question = Str::trimToNull($question);

        if ($repository === null || $question === null) {
            return $this->invalidArgs('Name the repository and ask the question.');
        }

        $reader = CodingChatSettingEnum::factoryAgentFor($this->app, $this->company, $repository);

        if ($reader === null) {
            return $this->denied(
                'This company has no factory agent, so no repository can be read.',
                guidance: 'Say plainly that the repository could not be read and why. Do not describe it.'
            );
        }

        try {
            $checkout = new CodingChatCheckouts($reader)->forTask('Repo: ' . $repository);
        } catch (ValidationException $e) {
            return $this->notFound(
                ['repository' => $repository, 'error' => $e->getMessage()],
                'Nothing was asked. Use one of the folder names listed, or say the repository does not exist.'
            );
        }

        $title = Str::limit('Read ' . $checkout . ': ' . $question, 120);
        $asked = $this->openQuestion($task, $checkout) ?? $this->ask(
            $task,
            $reader,
            $checkout,
            $title,
            $question
        );

        return $this->ok(
            [
                'question_task_id' => $asked->getId(),
                'repository' => $checkout,
                'answered_by' => $reader->name,
            ],
            'The question is with ' . $reader->name . ' on the factory machine. End your turn now with one line '
                . 'saying you are waiting on it: task ' . $task->getId() . ' resumes with the answer. Do not mark '
                . 'it done or blocked.'
        );
    }

    /**
     * One open question per repository: a repeated turn rewords what it asks, and matching on the
     * question's text sent the factory the same two questions twice.
     */
    private function openQuestion(Task $task, string $checkout): ?Task
    {
        /** @var Task|null */
        return $task->children()
            ->open()
            ->where('title', 'like', 'Read ' . Str::escapeLike($checkout) . ':%')
            ->latest('id')
            ->first();
    }

    private function ask(
        Task $task,
        Agent $reader,
        string $checkout,
        string $title,
        string $question
    ): Task {
        $child = new AddTaskAction(
            $task->plan,
            new TaskData(
                plan: $task->plan,
                title: $title,
                sequence: (int) $task->sequence,
                description: implode("\n\n", [
                    'Repo: ' . $checkout,
                    'A question from task #' . $task->getId() . ' ("' . $task->title . '"):',
                    $question,
                    'Answer it from the repository itself: read CLAUDE.md and specs/ first, then the code. Give '
                        . 'the file path for every finding, and say plainly what you looked for and did not find. '
                        . 'Read-only: change nothing, no commits, no push, no pull request.',
                ]),
                parentTask: $task,
            ),
        )->execute();

        $child->handTo($reader);

        return $child;
    }
}
