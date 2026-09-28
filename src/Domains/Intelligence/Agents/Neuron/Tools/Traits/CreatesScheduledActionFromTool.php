<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Scheduling\Actions\CreateScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Throwable;

/**
 * Shared create-and-report path for the schedule_* tools: validation errors from the action are
 * relayed to the model verbatim (they read as instructions), while unexpected faults are reported and
 * hidden behind a calm message.
 */
trait CreatesScheduledActionFromTool
{
    private function normalizeCron(?string $cron): ?string
    {
        return $cron !== null && trim($cron) !== '' ? trim($cron) : null;
    }

    /**
     * @return ScheduledAction|array<string, mixed> the created row, or a structured error to return verbatim
     */
    private function createScheduledAction(ScheduledActionData $data, string $failureMessage): ScheduledAction|array
    {
        try {
            return new CreateScheduledActionAction($data)->execute();
        } catch (ValidationException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'error', 'message' => $failureMessage];
        }
    }

    /**
     * A deterministic, system-authored receipt posted into the conversation the moment the row is
     * committed — the real id + exact fire time straight from the persisted action. The end user relies on
     * THIS, not the model's prose (which drifts): if there's no row, there's no receipt. Best-effort and a
     * no-op without an agent/session.
     */
    private function postScheduleReceipt(
        ?Agent $agent,
        ?Session $session,
        ScheduledAction $action
    ): void {
        if ($agent === null || $session === null) {
            return;
        }

        $label = $action->action_type === ScheduledActionTypeEnum::REMINDER->value ? 'Reminder' : 'Task';
        $when = $action->run_at->copy()->setTimezone($action->timezone)->format('M j, Y g:i A');
        $recurring = $action->isRecurring() ? ' · recurring' : '';

        $text = "✅ {$label} scheduled — #{$action->getId()}, fires {$when} ({$action->timezone}){$recurring}";

        try {
            new KanvasConversationStore()->appendAssistantMessageForSession(
                appsId: $action->apps_id,
                companiesId: $action->companies_id,
                sessionId: (string) $session->uuid,
                agentClass: $agent->type?->handler ?? $agent::class,
                content: $text,
                agentId: $agent->getId(),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
