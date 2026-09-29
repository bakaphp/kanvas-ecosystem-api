<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use Baka\Support\Str;
use stdClass;

/**
 * Company-held pools: plan entitlements and courtesy-pass quotas.
 *
 * Both are the same shape — "this organization holds N of something, issued D1, expires D2, M
 * used" — and an organization holds several over time, so a flat custom field per name cannot
 * represent them. They are stored as JSON arrays on Organization (plan decision 1.6.1a), which is
 * also the grain `rpt_empresa_plan` / `rpt_cortesia` flatten to: one row per array element.
 */
class EntitlementMapper
{
    /**
     * @param array<int, string> $planNames legacy plans.id => name
     * @param array<int, string> $tierNames legacy plans_details.id => name
     *
     * @return array<string, mixed>
     */
    public static function planFromIntras(stdClass $row, array $planNames = [], array $tierNames = []): array
    {
        // `available_tickets` is what is *left*, not what was contracted — SIPGO decrements it
        // as passes are consumed. Storing it as the total and then subtracting `used` again
        // double-counted the consumption: Banco Popular came out with 2 cupos and 168 usados.
        // The contracted figure is the three columns added together.
        $remaining = (int) ($row->available_tickets ?? 0) + (int) ($row->additional_tickets ?? 0);
        $used = (int) ($row->used_tickets ?? 0);

        return array_filter([
            'plan' => Str::trimToNull((string) ($planNames[(int) ($row->plans_id ?? 0)] ?? '')),
            'tier' => Str::trimToNull((string) ($tierNames[(int) ($row->plans_details_id ?? 0)] ?? '')),
            // The legacy "ACTIVO" status is expiration_date in the future AND tickets left, which
            // the Gestor recomputes in JS. Stored as the raw numbers so the flatten step derives
            // it once instead of every caller guessing.
            'tickets' => $remaining + $used,
            'used' => $used,
            'issued' => self::date($row->issued_date ?? null),
            'expires' => self::date($row->expiration_date ?? null),
            'consumed' => (bool) ($row->was_consumed ?? false),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param array<int, string> $eventNames legacy events.id => name
     *
     * @return array<string, mixed>
     */
    public static function courtesyPoolFromIntras(stdClass $row, array $eventNames = []): array
    {
        return array_filter([
            'evento' => Str::trimToNull((string) ($eventNames[(int) ($row->events_id ?? 0)] ?? '')),
            'amount' => (int) ($row->amount ?? 0),
            'issued' => self::date($row->issue_date ?? null),
            'expires' => self::date($row->expiration_date ?? null),
            'es_intercambio' => (bool) ($row->is_exchange ?? false),
            'comentario' => Str::trimToNull((string) ($row->comment ?? '')),
        ], fn ($value) => $value !== null);
    }

    /**
     * A per-participant courtesy pass → the columns on `participant_passes`.
     *
     * The legacy `is_credit` / `is_exchange` pair distinguishes a credit note from a swap; neither
     * has a Kanvas column, so they ride in the pass `payload` rather than being lost. `used_date`
     * being set is what makes a pass consumed — Kanvas has no separate status column.
     *
     * @return array<string, mixed>
     */
    public static function participantPassFromIntras(stdClass $row): array
    {
        return [
            'issue_date' => self::date($row->issue_date ?? null),
            'expiration_date' => self::date($row->expiration_date ?? null),
            'used_date' => self::date($row->used_date ?? null),
            'payload' => array_filter([
                'es_intercambio' => (bool) ($row->is_exchange ?? false),
                'es_credito' => (bool) ($row->is_credit ?? false),
                'comentario' => Str::trimToNull((string) ($row->comment ?? '')),
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * Legacy datetimes arrive as strings; only the date half is ever filtered on.
     */
    private static function date(mixed $value): ?string
    {
        $value = Str::trimToNull((string) ($value ?? ''));

        return $value === null ? null : substr($value, 0, 10);
    }
}
