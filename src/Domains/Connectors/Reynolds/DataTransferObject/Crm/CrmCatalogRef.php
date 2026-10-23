<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

/**
 * A lead type or source as the CRM reports it. The id is the CRM's; the name is what the company's
 * catalog is keyed on.
 */
class CrmCatalogRef extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $name = null
    ) {
    }
}
