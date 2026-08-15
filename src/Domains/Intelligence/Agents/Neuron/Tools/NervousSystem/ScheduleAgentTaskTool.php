<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\CreatesScheduledActionFromTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesConversationHuman;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionTimezoneResolver;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Schedule Agent Task', category: 'nervous_system')]
class ScheduleAgentTaskTool extends Tool implements HasRunKey
{
    use CreatesScheduledActionFromTool;
    use HasKanvasContext;
    use ResolvesConversationHuman;
    use TrackByInputs;

    public function __construct(
        private readonly ?Agent $agent = null,
        private readonly ?Session $session = null,
    ) {
        parent::__construct(
            name: 'schedule_agent_task',
            description: 'Schedule yourself to do something at a future time — you will be woken with the '
                . 'instruction and can use all your tools then. Call current_time FIRST, then pass run_at as '
                . '"YYYY-MM-DD HH:MM" in the user\'s local time. For a repeating task pass recurrence_cron '
                . '(standard 5-field cron, at most every 15 minutes) and omit run_at. Use schedule_reminder '
                . 'instead if you only need to deliver a message.',
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
                name: 'instruction',
                type: PropertyType::STRING,
                description: 'What you should do when woken, written as an instruction to yourself.',
                required: true,
            ),
            new ToolProperty(
                name: 'run_at',
                type: PropertyType::STRING,
                description: 'When to run, as "YYYY-MM-DD HH:MM" in the user\'s local time. Required unless '
                    . 'recurrence_cron is set.',
                required: false,
            ),
            new ToolProperty(
                name: 'recurrence_cron',
                type: PropertyType::STRING,
                description: 'Optional standard 5-field cron for a repeating task, e.g. "0 8 * * 1-5". At most '
                    . 'every 15 minutes. Evaluated in the user\'s timezone.',
                required: false,
            ),
            new ToolProperty(
                name: 'repeat_until',
                type: PropertyType::STRING,
                description: 'Optional "YYYY-MM-DD HH:MM" after which a recurring task stops.',
                required: false,
            ),
            new ToolProperty(
                name: 'max_occurrences',
                type: PropertyType::INTEGER,
                description: 'Optional cap on how many times a recurring task runs.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $instruction,
        ?string $run_at = null,
        ?string $recurrence_cron = null,
        ?string $repeat_until = null,
        ?int $max_occurrences = null,
    ): array {
        if ($this->agent === null) {
            return ['status' => 'error', 'message' => 'No agent is in scope, so I cannot schedule a task.'];
        }

        $instruction = trim($instruction);
        if ($instruction === '') {
            return ['status' => 'error', 'message' => 'The task needs an instruction.'];
        }

        $resolver = new ScheduledActionTimezoneResolver();
        $timezone = $resolver->resolve($this->company, $this->user);

        try {
            $runAt = $resolver->parseInZone($run_at, $timezone);
            $repeatUntil = $resolver->parseInZone($repeat_until, $timezone);
        } catch (Throwable) {
            return [
                'status' => 'error',
                'message' => 'I could not understand that date/time. Use "YYYY-MM-DD HH:MM".',
            ];
        }

        $action = $this->createScheduledAction(
            new ScheduledActionData(
                app: $this->app,
                company: $this->company,
                // "for me" = the human in the conversation, not the agent's own context user.
                user: $this->conversationHuman($this->session) ?? $this->user,
                type: ScheduledActionTypeEnum::AGENT_TASK,
                timezone: $timezone,
                runAt: $runAt,
                agent: $this->agent,
                instruction: $instruction,
                channel: $this->session?->channel?->slug,
                sessionUuid: $this->session?->uuid,
                sourceEntityType: $this->session?->entity_namespace,
                sourceEntityId: $this->session?->entity_id !== null ? (string) $this->session->entity_id : null,
                recurrenceCron: $this->normalizeCron($recurrence_cron),
                recurrenceEndsAt: $repeatUntil,
                maxOccurrences: $max_occurrences,
            ),
            'I could not schedule that task right now.',
        );

        if (is_array($action)) {
            return $action;
        }

        $this->postScheduleReceipt(
            $this->agent,
            $this->session,
            $action
        );

        return [
            'status' => 'success',
            'scheduled_action_id' => $action->getId(),
            'runs_at' => $action->run_at->toIso8601String(),
            'timezone' => $timezone,
            'recurring' => $action->isRecurring(),
            'message' => 'Task scheduled. Do not schedule it again.',
        ];
    }
}
