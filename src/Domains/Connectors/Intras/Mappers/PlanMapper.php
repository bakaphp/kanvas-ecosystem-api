<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use stdClass;

class PlanMapper
{
    public static function planToProduct(stdClass $row, string $agencyName): array
    {
        return [
            'name' => trim($row->name),
            'description' => 'Corporate training plan - ' . $agencyName,
            'custom_fields' => [
                'benefits_discount' => $row->benefits_discount ?? 0,
            ],
        ];
    }

    public static function planDetailToVariant(stdClass $row): array
    {
        return [
            'name' => trim($row->name),
            'price' => (float) $row->price_per_ticket,
            'custom_fields' => [
                'min_tickets' => $row->min_tickets,
                'max_tickets' => $row->max_tickets,
                'currency' => $row->currency_name ?? 'USD',
            ],
        ];
    }
}
