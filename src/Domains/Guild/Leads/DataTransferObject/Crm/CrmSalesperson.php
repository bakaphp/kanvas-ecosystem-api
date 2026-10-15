<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

/**
 * The CRM's id is kept for the record only: the owner is resolved by name.
 */
class CrmSalesperson extends Data
{
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $name = null
    ) {
    }
}
