<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\CreatesScheduledActionFromTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesConversationHuman;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionTimezoneResolver;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Lets an agent schedule a future reminder — a message delivered to a teammate at a set time, once or
 * on a recurring cron. Deterministic (no LLM at fire time). The recipient defaults to the person the
 * agent is talking to; a different recipient must be a verified member of this company (never a
 * free-typed outside address).
 */
#[AgentTool(name: 'Schedule Reminder', category: 'nervous_system')]
class ScheduleReminderTool extends Tool implements HasRunKey
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
            name: 'schedule_reminder',
            description: 'Schedule a reminder message to be delivered at a future time — for the person you are '
                . 'talking to, or a named teammate. Call current_time FIRST to know "now", then pass run_at as '
                . '"YYYY-MM-DD HH:MM" in the user\'s local time. For a repeating reminder ("every weekday at 9am") '
                . 'pass recurrence_cron as a standard 5-field cron and omit run_at. You cannot remind an outside '
                . 'email — only this company\'s members.',
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
                name: 'message',
                type: PropertyType::STRING,
                description: 'What the reminder should say.',
                required: true,
            ),
            new ToolProperty(
                name: 'run_at',
                type: PropertyType::STRING,
                description: 'When to fire, as "YYYY-MM-DD HH:MM" in the user\'s local time. Required unless '
                    . 'recurrence_cron is set.',
                required: false,
            ),
            new ToolProperty(
                name: 'recurrence_cron',
                type: PropertyType::STRING,
                description: 'Optional standard 5-field cron for a repeating reminder, e.g. "0 9 * * 1-5" '
                    . '(weekdays 9am) or "*/30 * * * *" (every 30 min). Evaluated in the user\'s timezone.',
                required: false,
            ),
            new ToolProperty(
                name: 'repeat_until',
                type: PropertyType::STRING,
                description: 'Optional "YYYY-MM-DD HH:MM" after which a recurring reminder stops.',
                required: false,
            ),
            new ToolProperty(
                name: 'max_occurrences',
                type: PropertyType::INTEGER,
                description: 'Optional cap on how many times a recurring reminder fires.',
                required: false,
            ),
            new ToolProperty(
                name: 'recipient_email',
                type: PropertyType::STRING,
                description: 'Optional teammate to remind instead of the current user. Must be a member of this '
                    . 'company.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $message,
        ?string $run_at = null,
        ?string $recurrence_cron = null,
        ?string $repeat_until = null,
        ?int $max_occurrences = null,
        ?string $recipient_email = null,
    ): array {
        $message = trim($message);
        if ($message === '') {
            return ['status' => 'error', 'message' => 'The reminder needs a message.'];
        }

        $resolver = new ScheduledActionTimezoneResolver();
        $timezone = $resolver->resolve($this->company, $this->user);

        $recipient = $this->resolveRecipient($recipient_email);
        if (is_array($recipient)) {
            return $recipient;
        }

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
                user: $recipient,
                type: ScheduledActionTypeEnum::REMINDER,
                timezone: $timezone,
                runAt: $runAt,
                agent: $this->agent,
                message: $message,
                channel: $this->session?->channel?->slug,
                sessionUuid: $this->session?->uuid,
                sourceEntityType: $this->session?->entity_namespace,
                sourceEntityId: $this->session?->entity_id !== null ? (string) $this->session->entity_id : null,
                recurrenceCron: $this->normalizeCron($recurrence_cron),
                recurrenceEndsAt: $repeatUntil,
                maxOccurrences: $max_occurrences,
            ),
            'I could not schedule that reminder right now.',
        );

        if (is_array($action)) {
            return $action;
        }

        $this->postScheduleReceipt($this->agent, $this->session, $action);

        return [
            'status' => 'success',
            'scheduled_action_id' => $action->getId(),
            'fires_at' => $action->run_at->toIso8601String(),
            'timezone' => $timezone,
            'recurring' => $action->isRecurring(),
            'recipient' => $recipient->email,
            'message' => 'Reminder scheduled. Do not schedule it again.',
        ];
    }

    /**
     * @return Users|array<string, mixed> the resolved recipient, or a structured error to return verbatim
     */
    private function resolveRecipient(?string $recipientEmail): Users|array
    {
        $recipientEmail = trim((string) $recipientEmail);
        if ($recipientEmail === '') {
            // "Remind me" = the human in the conversation, not $this->user — on an in-app agent surface
            // $this->user is the agent's own user, so defaulting to it schedules the reminder for the
            // agent itself. Fall back to $this->user only when there's no human subject.
            return $this->conversationHuman($this->session) ?? $this->user;
        }

        try {
            /** @var Users $recipient */
            $recipient = UsersRepository::getUserOfAppByEmail($recipientEmail, $this->app);
            UsersRepository::belongsToCompany($recipient, $this->company);

            return $recipient;
        } catch (ModelNotFoundException | ExceptionsModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "No teammate in this company has the email {$recipientEmail}. "
                    . 'Confirm who to remind, or leave it to remind the current user.',
            ];
        }
    }
}
