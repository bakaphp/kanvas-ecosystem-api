<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Scheduling\Actions\CreateScheduledActionAction;
use Kanvas\NervousSystem\Scheduling\DataTransferObject\ScheduledAction as ScheduledActionData;
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
}
