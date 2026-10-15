<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

class CrmParticipantAdd extends Data
{
    public function __construct(
        public readonly string $firstname,
        public readonly string $relationship_id,
        public readonly ?string $lastname = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null
    ) {
    }
}
