<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSources\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Models\LeadType;
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
        $this->leads_types_id = self::resolveLeadTypeId($this->leads_types_id, $this->company, $this->app);
    }

    public static function fromMultiple(Apps $app, Companies $company, array $data): self
    {
        return new self(
            app: $app,
            company: $company,
            leads_types_id: $data['leads_types_id'] ?? null,
            name: $data['name'],
            is_active: $data['is_active'],
            description: $data['description'] ?? null
        );
    }

    protected static function resolveLeadTypeId(int|string|null $leadsTypesId, Companies $company, Apps $app): ?int
    {
        if ($leadsTypesId === null || $leadsTypesId === '' || $leadsTypesId === 0) {
            return null;
        }

        try {
            if (is_numeric($leadsTypesId)) {
                return LeadType::getByIdFromCompanyApp((int) $leadsTypesId, $company, $app)->getId();
            }

            return LeadType::getByUuidFromCompanyApp($leadsTypesId, company: $company, app: $app)->getId();
        } catch (ModelNotFoundException) {
            return null;
        }
    }
}
