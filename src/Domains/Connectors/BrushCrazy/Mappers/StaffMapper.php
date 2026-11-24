<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Mappers;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;

/**
 * Turns one BrushCrazy user plus their `studio_employees` rows for one studio into what that
 * studio's Facilitator needs. Free of model lookups so it stays unit-testable.
 *
 * BrushCrazy never updates an employment in place: a position, wage or studio change stamps
 * `transitioned_at` on the old row and inserts a new one, and termination stamps `terminated_at`.
 * So the rows are a history, and a row is current only while both stamps are NULL.
 *
 * Staff are identified by these rows, never by role — a terminated employee is reset to the
 * `Customer` role.
 */
class StaffMapper
{
    /**
     * Custom fields with a null value are absent at the source and must be cleared, not skipped:
     * a rehire has to drop the old termination date.
     *
     * @param  array<int, object>  $employments
     * @return array{is_deleted: bool, custom_fields: array<string, mixed>}
     */
    public static function map(object $user, array $employments, bool $isOwner): array
    {
        usort($employments, fn (object $a, object $b) => (int) $a->id <=> (int) $b->id);

        $current = self::current($employments);
        $latest = $current ?? ($employments !== [] ? $employments[array_key_last($employments)] : null);
        $stint = self::latestStint($employments);
        $userDeleted = ($user->deleted_at ?? null) !== null;

        return [
            // Only a deleted user deletes the Facilitator. Former staff, staff moved to another
            // studio and instructors only covering here stay live and inactive, because classes
            // list only live Facilitators and the ones they taught must keep showing them.
            'is_deleted' => $userDeleted,
            'custom_fields' => [
                CustomFieldEnum::BRUSHCRAZY_USER_ID->value => (int) $user->id,
                CustomFieldEnum::BRUSHCRAZY_STAFF_ACTIVE->value => ! $userDeleted && ($isOwner || $current !== null),
                CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_POSITION->value => Str::trimToNull($latest?->position ?? null),
                CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_BADGE->value => isset($latest->employee_id) ? (int) $latest->employee_id : null,
                CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_HIRED_AT->value => self::stintStart($stint),
                CustomFieldEnum::BRUSHCRAZY_EMPLOYEE_TERMINATED_AT->value => $stint !== []
                    ? self::utc($stint[array_key_last($stint)]->terminated_at ?? null)
                    : null,
                CustomFieldEnum::BRUSHCRAZY_STUDIO_OWNER->value => $isOwner,
                CustomFieldEnum::BRUSHCRAZY_EMPLOYMENT_HISTORY->value => $employments !== []
                    ? array_map(self::history(...), $employments)
                    : null,
            ],
        ];
    }

    /**
     * The latest wins when more than one row looks current; BrushCrazy itself defends against
     * stray current rows left by old bugs (ChangePositionController).
     *
     * @param  array<int, object>  $employments
     */
    private static function current(array $employments): ?object
    {
        $current = array_filter(
            $employments,
            fn (object $row) => ($row->terminated_at ?? null) === null && ($row->transitioned_at ?? null) === null
        );

        return $current !== [] ? $current[array_key_last($current)] : null;
    }

    /**
     * Rows since the last termination, or the stint that termination closed when nothing followed it.
     *
     * @param  array<int, object>  $employments
     * @return array<int, object>
     */
    private static function latestStint(array $employments): array
    {
        $stints = [[]];

        foreach ($employments as $row) {
            $stints[array_key_last($stints)][] = $row;

            if (($row->terminated_at ?? null) !== null) {
                $stints[] = [];
            }
        }

        $stints = array_values(array_filter($stints));

        return $stints !== [] ? $stints[array_key_last($stints)] : [];
    }

    /**
     * The earliest stamp of the stint, because each transition writes a fresh `hired_at`.
     *
     * @param  array<int, object>  $stint
     */
    private static function stintStart(array $stint): ?string
    {
        $starts = array_filter(array_map(
            fn (object $row) => self::utc($row->hired_at ?? $row->invited_at ?? null),
            $stint
        ));

        if ($starts === []) {
            return null;
        }

        sort($starts);

        return $starts[0];
    }

    /**
     * @return array<string, mixed>
     */
    private static function history(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'badge' => isset($row->employee_id) ? (int) $row->employee_id : null,
            'position' => Str::trimToNull($row->position ?? null),
            'hired_at' => self::utc($row->hired_at ?? null),
            'invited_at' => self::utc($row->invited_at ?? null),
            'verified_at' => self::utc($row->verified_at ?? null),
            'terminated_at' => self::utc($row->terminated_at ?? null),
            'transitioned_at' => self::utc($row->transitioned_at ?? null),
        ];
    }

    /** BrushCrazy's app timezone is UTC, so its timestamps are stored as UTC wall clock. */
    private static function utc(?string $value): ?string
    {
        $value = Str::trimToNull($value);

        return $value !== null ? Carbon::parse($value, 'UTC')->toIso8601String() : null;
    }
}
