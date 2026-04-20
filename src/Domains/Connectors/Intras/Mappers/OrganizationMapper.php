<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use stdClass;

class OrganizationMapper
{
    public static function fromIntras(stdClass $row): array
    {
        return [
            'name' => trim($row->name),
            'custom_fields' => array_filter([
                'rnc' => $row->rnc ?? null,
                'classification' => $row->classification ?? null,
                'intras_is_prospect' => (bool) ($row->is_prospect ?? false),
                'total_employees' => $row->total_employees ?? null,
            ]),
        ];
    }
}
