<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject\Crm;

use Kanvas\Connectors\Reynolds\Enums\CrmTypeEnum;
use Spatie\LaravelData\Data;

/**
 * Both ids are the CRM's own, not Kanvas'. `type` says which CRM they belong to.
 */
class CrmIdentity extends Data
{
    public function __construct(
        public readonly CrmTypeEnum $type,
        public readonly string $client_id,
        public readonly ?string $lead_id = null
    ) {
    }
}
