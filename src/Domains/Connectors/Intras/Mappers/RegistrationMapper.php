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

    public static function fromIntras(stdClass $row): array
    {
        return [
            'ticket_price' => $row->investment ?? 0,
            'discount' => $row->discount ?? 0,
            'invoice_date' => $row->invoice_date ?? null,
            'metadata' => array_filter([
                'reserved_tickets' => $row->reserved_tickets ?? null,
                'amount_covered_by_company' => $row->amount_covered_by_company ?? null,
                'is_assisting' => (bool) ($row->is_assisting ?? false),
                'assisted_event' => (bool) ($row->assisted_event ?? false),
                'completed_event' => (bool) ($row->completed_event ?? false),
                'comments' => $row->comments ?? null,
            ]),
        ];
    }
}
