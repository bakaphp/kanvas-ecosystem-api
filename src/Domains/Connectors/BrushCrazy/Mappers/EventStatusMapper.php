<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Mappers;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\EventStatusEnum;

/**
 * The single place the derived BrushCrazy status is computed. Both the bulk import and the mirror
 * need it, and two copies would drift into producing different statuses for the same row.
 *
 * Reproduces `Event::currentStatus()` for private events; lessons and workshops have no ladder,
 * only `published_at`.
 */
class EventStatusMapper
{
    public static function resolve(CalendarableTypeEnum $morph, object $row, ?Carbon $now = null): EventStatusEnum
    {
        if (($row->deleted_at ?? null) !== null) {
            return EventStatusEnum::CANCELLED;
        }

        if ($morph->hasApprovalLadder()) {
            return self::resolveApprovalLadder($row);
        }

        if (($row->published_at ?? null) === null) {
            return EventStatusEnum::DRAFT;
        }

        return self::hasEnded($row, $now)
            ? EventStatusEnum::COMPLETED
            : EventStatusEnum::PUBLISHED;
    }

    private static function resolveApprovalLadder(object $row): EventStatusEnum
    {
        return match (true) {
            ($row->finalized_at ?? null) !== null => EventStatusEnum::FINALIZED,
            ($row->deposit_paid_at ?? null) !== null => EventStatusEnum::DEPOSIT_PAID,
            ($row->deposit_requested_at ?? null) !== null => EventStatusEnum::DEPOSIT_REQUESTED,
            ($row->approved_at ?? null) !== null => EventStatusEnum::APPROVED,
            default => EventStatusEnum::PENDING,
        };
    }

    /**
     * No timezone argument on purpose. `Calendarable::setEndAttribute()` stores UTC, so the raw
     * column and `now()` are both absolute instants and comparing them in UTC gives the same
     * answer as the source's studio-local comparison — without inheriting the source's
     * hardcoded-Denver conversion.
     */
    private static function hasEnded(object $row, ?Carbon $now): bool
    {
        $end = $row->end ?? null;

        if ($end === null) {
            return false;
        }

        return Carbon::parse($end, 'UTC')->lessThan($now ?? Carbon::now('UTC'));
    }
}
