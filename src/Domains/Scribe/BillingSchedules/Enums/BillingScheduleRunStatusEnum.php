<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Enums;

/**
 * processing → invoiced (the draft exists, committed with the schedule's advance) → submitted. A
 * submission that failed leaves the run `failed` with a next_retry_at; the next run resumes it.
 */
enum BillingScheduleRunStatusEnum: string
{
    case PROCESSING = 'processing';
    case INVOICED = 'invoiced';
    case SUBMITTED = 'submitted';
    case FAILED = 'failed';
}
