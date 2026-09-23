<?php

declare(strict_types=1);

namespace Kanvas\Imports\Enums;

use Illuminate\Support\Facades\File;
use Kanvas\Imports\DataTransferObject\ImportTemplate;

enum ImportTemplateEnum: string
{
    case DEALER_VEHICLE_CSV = 'dealer_vehicle_csv';

    public function template(): ImportTemplate
    {
        return ImportTemplate::fromDefinition(
            File::json(
                resource_path('import-templates/' . str_replace('_', '-', $this->value) . '.json'),
                JSON_THROW_ON_ERROR
            )
        );
    }
}
