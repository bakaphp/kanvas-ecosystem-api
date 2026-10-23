<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

/**
 * The CRM's dealer follow-up flags, raw.
 */
class CrmFollowUp extends Data
{
    public function __construct(
        public readonly ?string $phone = null,
        public readonly ?string $mail = null,
        public readonly ?string $email = null,
        public readonly ?string $text = null
    ) {
    }
}
