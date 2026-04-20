<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use stdClass;

class LeadMapper
{
    /**
     * Map SIPGO quote status to Kanvas pipeline stage slug.
     */
    public static function quoteStatusToStage(int $statusId): string
    {
        return match ($statusId) {
            1 => 'drafting',
            2 => 'discarded',
            3 => 'pending-approval',
            4 => 'won',
            5 => 'lost',
            6 => 'voided',
            7 => 'cancelled',
            8, 9, 10, 13, 14 => 'referred',
            15 => 'won-plan',
            16 => 'pending-presentation',
            default => 'drafting',
        };
    }

    public static function fromIntras(stdClass $row): array
    {
        return [
            'title' => 'Quote #' . ($row->number ?? $row->id),
            'custom_fields' => array_filter([
                'quote_amount' => $row->quote_amount ?? null,
                'total_participants' => $row->total_participants ?? null,
                'classification' => $row->classification ?? null,
                'requested_date' => $row->requested_date ?? null,
                'sent_date' => $row->sent_date ?? null,
                'info_objectives' => $row->info_objectives ?? null,
                'info_issues' => $row->info_issues ?? null,
            ]),
        ];
    }
}
