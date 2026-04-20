<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use stdClass;

class FacilitatorMapper
{
    public static function fromIntras(stdClass $row): array
    {
        return [
            'firstname' => trim($row->first_name),
            'lastname' => trim($row->last_name),
            'custom_fields' => array_filter([
                'identification' => $row->identification ?? null,
                'cv' => $row->cv ?? null,
                'intras_facilitator_status' => $row->facilitators_statuses_id ?? null,
            ]),
        ];
    }
}
