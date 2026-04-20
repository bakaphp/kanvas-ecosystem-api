<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use stdClass;

class ParticipantMapper
{
    public static function fromIntras(stdClass $row): array
    {
        return [
            'firstname' => trim($row->first_name),
            'lastname' => trim($row->last_name),
            'custom_fields' => array_filter([
                'position' => $row->position ?? null,
                'identification' => $row->identification ?? null,
                'intras_is_prospect' => (bool) ($row->is_prospect ?? false),
                'intras_classification' => $row->classification ?? null,
            ]),
        ];
    }
}
