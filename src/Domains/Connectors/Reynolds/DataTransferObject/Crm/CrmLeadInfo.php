<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

class CrmLeadInfo extends Data
{
    public function __construct(
        public readonly ?CrmCatalogRef $type = null,
        public readonly ?CrmCatalogRef $source = null,
        public readonly ?string $status = null,
        public readonly ?CrmSalesperson $salesperson = null,
        public readonly ?string $description = null
    ) {
    }
}
