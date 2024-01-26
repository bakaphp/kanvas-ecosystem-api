<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class LeadType extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public string $name,
        public string $description,
        public int $is_active,
        public int $is_default
    ) {
    }
}
