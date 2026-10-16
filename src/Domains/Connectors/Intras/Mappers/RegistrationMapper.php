<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use stdClass;

class RegistrationMapper
{
    /**
     * SIPGO's de facto "attended" definition, used by every legacy report except
     * participants_profiles — which uses IN (1,2,7,8,6,9), dropping 11 and 14. That inconsistency
     * is why this lives in one place: the flatten step writes it to `rpt_inscripcion.es_asistente`
     * so the UI, the agent and every report share one answer.
     *
     * Ids are legacy `inscriptions_types.id`, matched against the value stored on each synced
     * ParticipantType.
     */
    public const array ATTENDING_INSCRIPTION_TYPE_IDS = [1, 2, 6, 7, 8, 9, 11, 14];

    public static function isAttending(?int $inscriptionTypeId): bool
    {
        return $inscriptionTypeId !== null
            && in_array($inscriptionTypeId, self::ATTENDING_INSCRIPTION_TYPE_IDS, true);
    }

    /**
     * The legacy column is a DATETIME against a DATE column here, and it carries MySQL zero
     * dates (`0000-00-00 00:00:00`) for "never invoiced".
     *
     * Both matter now that the importer updates existing rows rather than only creating them:
     * the time half made every already-imported registration look changed on every run, and a
     * zero date is not a date.
     */
    public static function invoiceDate(mixed $value): ?string
    {
        $date = substr(trim((string) ($value ?? '')), 0, 10);

        return $date === '' || $date === '0000-00-00' ? null : $date;
    }

    /**
     * The three named lookups ride in `metadata` rather than custom fields.
     *
     * `programa` uses `$evp->set()`, but it applies to 4 rows in the whole legacy database.
     * `canal` applies to 54,089 — writing that as a custom field is 54k extra rows in
     * `apps_custom_fields` and 54k extra writes per run, for a value the flatten step reads once.
     * `metadata` is already a column on the registration and already written on the same pass.
     *
     * @param array<int, string> $channelNames legacy channels.id => name
     * @param array<int, string> $planNames    legacy companies_plans.id => plan name
     * @param array<int, string> $sponsorNames legacy companies.id => name
     *
     * @return array<string, mixed>
     */
    public static function fromIntras(
        stdClass $row,
        array $channelNames = [],
        array $planNames = [],
        array $sponsorNames = []
    ): array {
        $planId = (int) ($row->companies_plans_id ?? 0);

        return [
            'ticket_price' => $row->investment ?? 0,
            'discount' => $row->discount ?? 0,
            'invoice_date' => self::invoiceDate($row->invoice_date ?? null),
            'metadata' => array_filter([
                'reserved_tickets' => $row->reserved_tickets ?? null,
                'amount_covered_by_company' => $row->amount_covered_by_company ?? null,
                'is_assisting' => (bool) ($row->is_assisting ?? false),
                'assisted_event' => (bool) ($row->assisted_event ?? false),
                'completed_event' => (bool) ($row->completed_event ?? false),
                'comments' => $row->comments ?? null,
                'canal' => $channelNames[(int) ($row->channels_id ?? 0)] ?? null,
                'plan_id' => $planId > 0 ? $planId : null,
                'plan' => $planNames[$planId] ?? null,
                'sponsor' => $sponsorNames[(int) ($row->company_sponsor_id ?? 0)] ?? null,
            ], fn ($value) => $value !== null),
        ];
    }
}
