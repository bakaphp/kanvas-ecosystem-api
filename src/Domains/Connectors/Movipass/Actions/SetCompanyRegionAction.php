<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Inventory\Regions\Enums\CustomFieldEnum as RegionCustomFieldEnum;

class SetCompanyRegionAction
{
    public function __construct(
        protected readonly Companies $company,
        protected readonly int $regionId,
    ) {
    }

    public function execute(): void
    {
        $this->company->set(CustomFieldEnum::COMPANY_REGION_ID->value, $this->regionId);
        $this->company->set(RegionCustomFieldEnum::DEFAULT_REGION_ID->value, $this->regionId);
    }
}
