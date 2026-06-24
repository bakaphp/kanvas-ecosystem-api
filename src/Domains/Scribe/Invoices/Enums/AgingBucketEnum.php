<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Enums;

use Illuminate\Support\Carbon;

/**
 * Standard AR-aging buckets. Computed (not stored) — derived from (due_date, today).
 *
 * Used by EvaluateInvoiceAgingJob to emit aging_changed events on bucket transition, and by the
 * ArAgingRepository to group open invoices.
 *
 * @see plan §7.2 — aging is derived, not stored
 */
enum AgingBucketEnum: string
{
    case CURRENT = 'current';        // not yet due
    case BUCKET_1_30 = '1-30';       // 1–30 days overdue
    case BUCKET_31_60 = '31-60';
    case BUCKET_61_90 = '61-90';
    case BUCKET_90_PLUS = '90+';

    public static function forInvoice(?Carbon $dueDate, Carbon $today): self
    {
        if ($dueDate === null) {
            return self::CURRENT;
        }

        $daysOverdue = $today->diffInDays($dueDate, false);
        // diffInDays returns negative when $today is past $dueDate (i.e. overdue).
        $daysOverdue = -((int) $daysOverdue);

        return match (true) {
            $daysOverdue <= 0 => self::CURRENT,
            $daysOverdue <= 30 => self::BUCKET_1_30,
            $daysOverdue <= 60 => self::BUCKET_31_60,
            $daysOverdue <= 90 => self::BUCKET_61_90,
            default => self::BUCKET_90_PLUS,
        };
    }
}
