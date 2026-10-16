<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Contracts\RequiresSystemAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTeammates;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\LinksAskToTask;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesTaskForTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\NervousSystem\Plan\Actions\SnoozeTaskAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionTimezoneResolver;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Snooze Task', category: 'nervous_system')]
class SnoozeNervousSystemTaskTool extends Tool implements RequiresSystemAgent
{
    use FindsTeammates;
    use HasKanvasContext;
    use LinksAskToTask;
    use ReportsToolOutcome;
    use ResolvesTaskForTool;
    use TrackByInputs;

    public const int DEFAULT_WAKE_HOUR = 9;

    protected string $name = 'snooze_task';

    protected ?string $description = 'Park a task you are waiting on — a person who said "tomorrow", an answer that has '
        . 'not come — and be woken on that date to follow up. The task goes to status "waiting" and you are '
        . 'woken with its id and your reason. Snoozing again replaces the earlier wake, and closing the task '
        . 'cancels it. Pass until as "YYYY-MM-DD HH:MM" in the company\'s local time; leave it empty for '
        . 'tomorrow at 09:00. Use this instead of update_nervous_system_task_status for a wait, and instead '
        . 'of schedule_agent_task when the follow-up belongs to a task.';

    public function __construct(
        private readonly ?Agent $agent = null,
        private readonly ?Session $session = null,
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
                name: 'task_id',
                type: PropertyType::INTEGER,
                description: 'The id of the task you are waiting on.',
                required: true,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Who or what you are waiting on, and what they said, e.g. "Charlie: will send the '
                    . 'strategy Friday". Shown to you when you are woken.',
                required: true,
            ),
            new ToolProperty(
                name: 'until',
                type: PropertyType::STRING,
                description: 'When to be woken, as "YYYY-MM-DD HH:MM" in the company\'s local time. Use the date '
                    . 'the person gave. Empty means tomorrow at 09:00.',
                required: false,
            ),
            new ToolProperty(
                name: 'waiting_on_email',
                type: PropertyType::STRING,
                description: 'The email of the teammate who owes the answer, when the task waits on a person (find_teammate '
                    . 'turns a name into it). Their next message to you then arrives with this task in hand. Skip '
                    . 'it if you already passed task_id to send_email_to_user or send_slack_direct_message for this ask.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $task_id,
        string $reason,
        ?string $until = null,
        ?string $waiting_on_email = null,
    ): array {
        if ($this->agent === null) {
            return $this->failed('No agent is in scope, so the task cannot be snoozed.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            return $this->invalidArgs('Say what you are waiting on; the reason is shown to you when you are woken.');
        }

        $task = $this->resolveTaskOrError($task_id, "Task {$task_id} was not found in this project.");
        if (is_array($task)) {
            return $this->notFound(['task_id' => $task_id], guidance: $task['error']);
        }

        $status = TaskStatusEnum::tryFrom($task->status);
        if ($status !== null && $status->isTerminal()) {
            return $this->denied(
                sprintf('Task #%d is already %s and cannot be snoozed.', $task->getId(), $task->status),
                guidance: 'Reopen it with update_nervous_system_task_status first if the work is not finished.',
            );
        }

        $resolver = new ScheduledActionTimezoneResolver();
        $timezone = $resolver->resolve($this->company, $this->user);

        try {
            $wakeAt = $resolver->wakeAt($until, $timezone, self::DEFAULT_WAKE_HOUR);
        } catch (Throwable) {
            return $this->invalidArgs('I could not read that date. Use "YYYY-MM-DD HH:MM".');
        }

        // Recorded before the snooze so the wake instruction names the person and the thread.
        $waitingOn = $this->recordWaitingOn($task, $waiting_on_email);

        try {
            $wake = new SnoozeTaskAction(
                task: $task,
                agent: $this->agent,
                until: $wakeAt,
                reason: $reason,
                session: $this->session,
            )->execute();
        } catch (ValidationException $e) {
            return $this->invalidArgs($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->failed('The task could not be snoozed right now.');
        }

        return $this->ok([
            'task_id' => $task->getId(),
            'status' => TaskStatusEnum::WAITING->value,
            'wake_at' => $wake->run_at->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
            'timezone' => $timezone,
            'scheduled_action_id' => $wake->getId(),
            ...$waitingOn,
        ], guidance: 'The task is parked and you will be woken then. Do not snooze it again now, and do not post a reminder yourself.');
    }

    /**
     * @return array<string, mixed>
     */
    private function recordWaitingOn(Task $task, ?string $email): array
    {
        $email = trim((string) $email);

        if ($email === '') {
            return [];
        }

        $person = $this->teammateByEmail($email, $this->agent);

        if ($person === null) {
            return ['waiting_on_warning' => "No teammate in this company has the email {$email}; the task was snoozed without recording who it waits on."];
        }

        return $this->recordAskOnTask($task, $person, $this->session?->channel_id);
    }
}
