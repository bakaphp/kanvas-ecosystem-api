<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

/**
 * Both ids are Kanvas', unlike everything under the identity.
 */
class CrmParticipantRemove extends Data
{
    public function __construct(
        public readonly string $people_id,
        public readonly string $relationship_id
    ) {
    }
}
