<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Spatie\LaravelData\Data;

class LeadUpdateInput extends Data
{
    /**
     * __construct.
     */
    public function __construct(
        public readonly int $branch_id,
        public readonly string $title,
        public readonly int $people_id,
        public readonly ?int $status_id = null,
        public readonly ?int $type_id = null,
        public readonly ?int $source_id = null,
        public readonly ?int $pipeline_stage_id = null,
        public readonly ?int $leads_owner_id = null,
        public readonly ?int $receiver_id = null,
        public readonly ?int $organization_id = null,
        public readonly ?string $description = null,
        public readonly ?string $reason_lost = null,
        public readonly array $custom_fields = [],
    ) {
    }
}
