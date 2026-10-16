<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

class CrmParticipants extends Data
{
    /**
     * @param list<CrmParticipantAdd> $add
     * @param list<CrmParticipantRemove> $remove
     */
    public function __construct(
        public readonly array $add = [],
        public readonly array $remove = []
    ) {
    }
}
