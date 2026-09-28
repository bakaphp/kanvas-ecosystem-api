<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Scheduling\Actions;

use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionTimezoneResolver;

class CreateScheduledActionAction
{
    /**
     * Hard ceiling for a one-off's horizon — a run_at further out than this is almost always an
     * LLM date-math slip, not intent.
     */
    private const int MAX_ONE_OFF_HORIZON_DAYS = 366;

    public function __construct(
        protected readonly ScheduledActionData $data,
    ) {
    }

    public function execute(): ScheduledAction
    {
        $this->assertContentPresent();

        $timezone = $this->data->timezone;
        if (! new ScheduledActionTimezoneResolver()->isValidTimezone($timezone)) {
            throw new ValidationException("Invalid timezone '{$timezone}'.");
        }

        $runAt = $this->resolveRunAt($timezone);

        return DB::connection('intelligence')->transaction(function () use ($runAt, $timezone): ScheduledAction {
            $action = new ScheduledAction();
            $action->apps_id = $this->data->app->getId();
            $action->companies_id = $this->data->company->getId();
            $action->users_id = $this->data->user->getId();
            $action->agent_id = $this->data->agent?->getId();
            $action->action_type = $this->data->type->value;
            $action->status = ScheduledActionStatusEnum::PENDING->value;
            $action->run_at = $runAt;
            $action->timezone = $timezone;
            $action->recurrence_cron = $this->data->recurrenceCron;
            $action->recurrence_ends_at = $this->data->recurrenceEndsAt;
            $action->max_occurrences = $this->data->maxOccurrences;
            $action->occurrences_count = 0;
            $action->payload = $this->data->toPayload();
            $action->channel = $this->data->channel;
            $action->session_uuid = $this->data->sessionUuid;
            $action->source_entity_type = $this->data->sourceEntityType;
            $action->source_entity_id = $this->data->sourceEntityId;
            $action->saveOrFail();

            $action->emitLedgerEvent('scheduled_action.created', payload: [
                'action_type' => $action->action_type,
                'run_at' => $action->run_at->toIso8601String(),
                'timezone' => $action->timezone,
                'recurring' => $action->isRecurring(),
                'recurrence_cron' => $action->recurrence_cron,
            ]);

            return $action;
        });
    }

    private function assertContentPresent(): void
    {
        $missing = match ($this->data->type) {
            ScheduledActionTypeEnum::REMINDER => trim((string) $this->data->message) === '',
            ScheduledActionTypeEnum::AGENT_TASK => trim((string) $this->data->instruction) === '',
        };

        if ($missing) {
            throw new ValidationException(
                $this->data->type === ScheduledActionTypeEnum::REMINDER
                    ? 'A reminder needs a message.'
                    : 'An agent task needs an instruction.',
            );
        }
    }

    private function resolveRunAt(string $timezone): Carbon
    {
        if ($this->data->isRecurring()) {
            return $this->resolveRecurringRunAt($timezone);
        }

        $runAt = $this->data->runAt;
        if ($runAt === null) {
            throw new ValidationException('A one-off scheduled action needs a run_at time.');
        }

        if ($runAt->lessThanOrEqualTo(Carbon::now())) {
            throw new ValidationException('run_at must be in the future.');
        }

        if ($runAt->greaterThan(Carbon::now()->addDays(self::MAX_ONE_OFF_HORIZON_DAYS))) {
            throw new ValidationException('run_at is too far in the future (max 1 year out).');
        }

        return $runAt;
    }

    private function resolveRecurringRunAt(string $timezone): Carbon
    {
        $cron = (string) $this->data->recurrenceCron;

        if (! CronExpression::isValidExpression($cron)) {
            throw new ValidationException("Invalid recurrence cron expression '{$cron}'.");
        }

        $this->assertRecurrenceIntervalAboveFloor($cron, $timezone);

        $runAt = $this->data->runAt;
        if ($runAt !== null) {
            if ($runAt->lessThanOrEqualTo(Carbon::now())) {
                throw new ValidationException('run_at must be in the future.');
            }

            return $runAt;
        }

        return Carbon::instance(
            new CronExpression($cron)->getNextRunDate(Carbon::now(), 0, false, $timezone),
        )->utc();
    }

    private function assertRecurrenceIntervalAboveFloor(string $cron, string $timezone): void
    {
        $expression = new CronExpression($cron);
        $first = $expression->getNextRunDate(Carbon::now(), 0, false, $timezone);
        $second = $expression->getNextRunDate($first, 0, false, $timezone);

        $intervalSeconds = $second->getTimestamp() - $first->getTimestamp();
        $floor = $this->data->type->minimumRecurrenceIntervalSeconds();

        if ($intervalSeconds < $floor) {
            $minutes = intdiv($floor, 60);

            throw new ValidationException(
                $this->data->type === ScheduledActionTypeEnum::AGENT_TASK
                    ? "An agent task can run at most every {$minutes} minutes."
                    : "A reminder can run at most once per minute.",
            );
        }
    }
}
