<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Actions;

use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;

/**
 * Soft-deletes the schedule and its run rows. Invoices it already created are untouched: they are
 * on the books (or waiting for approval) on their own.
 */
class DeleteBillingScheduleAction
{
    public function __construct(
        public readonly BillingSchedule $schedule,
    ) {
    }

    public function execute(): bool
    {
        $this->schedule->delete();

        $this->schedule->emitLedgerEvent(eventType: 'scribe.billing_schedule.deleted');

        return true;
    }
}
