<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use stdClass;

class RegistrationMapper
{
    /**
     * Map SIPGO inscription_type_id to Kanvas ParticipantType slug.
     */
    public static function inscriptionTypeToSlug(int $inscriptionTypeId): string
    {
        return match ($inscriptionTypeId) {
            1 => 'confirmed',
            2 => 'confirmed-plan',
            3 => 'cancelled',
            4 => 'interested',
            5 => 'reserved',
            6 => 'employee',
            7 => 'exchange',
            8 => 'courtesy',
            9 => 'credit',
            10 => 'interested-event',
            11 => 'program',
            14 => 'sponsor-guest',
            default => 'confirmed',
        };
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
