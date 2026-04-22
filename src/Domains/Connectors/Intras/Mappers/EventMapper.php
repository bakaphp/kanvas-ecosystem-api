<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Mappers;

use stdClass;

class EventMapper
{
    public static function fromIntras(stdClass $row): array
    {
        return [
            'name' => trim($row->name),
            'classification' => $row->classification ?? null,
        ];
    }

    public static function versionFromIntras(stdClass $row): array
    {
        return [
            'name' => trim($row->name),
            'version' => $row->version,
            'classification' => $row->classification ?? null,
            'price_per_ticket' => $row->price_per_ticket ?? 0,
            'max_capacity' => $row->max_capacity ?? 0,
            'metadata' => array_filter([
                'has_book' => (bool) ($row->has_book ?? false),
                'has_forum' => (bool) ($row->has_forum ?? false),
                'has_graduation' => (bool) ($row->has_graduation ?? false),
                'has_translations' => (bool) ($row->has_translations ?? false),
            ]),
        ];
    }
}
