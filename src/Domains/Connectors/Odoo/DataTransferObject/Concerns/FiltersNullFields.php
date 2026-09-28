<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\DataTransferObject\Concerns;

use Override;

/**
 * Odoo's `create`/`write` treat an explicit `null`/`""` in the payload as "clear this field" —
 * shared by every outbound Odoo DTO so an unset Kanvas value never overwrites existing Odoo data.
 */
trait FiltersNullFields
{
    #[Override]
    public function toArray(): array
    {
        return array_filter(parent::toArray(), fn ($value) => $value !== null && $value !== '');
    }
}
