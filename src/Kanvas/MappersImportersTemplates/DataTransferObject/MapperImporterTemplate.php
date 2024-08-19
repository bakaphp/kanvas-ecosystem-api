<?php

declare(strict_types=1);

namespace Kanvas\MappersImportersTemplates\DataTransferObject;

use Spatie\LaravelData\Data;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Companies\Models\Companies;

class MapperImporterTemplate extends Data
{
    public function __construct(
        public Apps $apps,
        public Users $users,
        public Companies $companies,
        public string $name,
        public array $attributes,
        public ?string $description = null,
    ) {
    }
}
