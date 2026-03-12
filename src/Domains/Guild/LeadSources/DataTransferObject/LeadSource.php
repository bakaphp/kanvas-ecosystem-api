<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSources\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class LeadSource extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public int|string|null $leads_types_id,
        public string $name,
        public bool $is_active,
        public ?string $description = null,
    ) {
    }

    public static function fromMultiple(Apps $app, Companies $company, array $data): self
    {
        return new self(
            app: $app,
            company: $company,
            leads_types_id: $data['leads_types_id'],
            name: $data['name'],
            is_active: $data['is_active'],
            description: $data['description'] ?? null
        );
    }
}
