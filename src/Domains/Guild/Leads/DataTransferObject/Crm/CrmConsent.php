<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

/**
 * The CRM's consent values exactly as it sent them. What each one means is the driver's to decide.
 */
class CrmConsent extends Data
{
    public function __construct(
        public readonly ?string $text_opt_status = null,
        public readonly ?string $opt_out = null,
        public readonly ?string $opt_out_use = null,
        public readonly ?CrmFollowUp $follow_up = null
    ) {
    }
}
