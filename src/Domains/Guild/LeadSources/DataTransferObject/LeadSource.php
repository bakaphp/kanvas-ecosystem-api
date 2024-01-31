<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSources\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class LeadSource extends Data
{
    public function __construct(
        public Apps $apps,
        public Companies $companies,
        public int $leads_types_id,
        public string $name,
        public string $description,
        public bool $is_active,
    ) {
    }
}
