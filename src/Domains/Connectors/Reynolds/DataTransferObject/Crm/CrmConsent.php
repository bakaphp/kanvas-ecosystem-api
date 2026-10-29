<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

/**
 * The CRM's consent values exactly as it sent them. What each one means is the driver's to decide.
 */
class CrmConsent extends Data
{
    public function __construct(
        public readonly ?CrmFollowUp $follow_up = null
    ) {
    }
}
