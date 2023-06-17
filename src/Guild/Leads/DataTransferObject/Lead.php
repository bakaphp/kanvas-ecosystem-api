<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Spatie\LaravelData\Data;

class Lead extends Data
{
    /**
     * __construct.
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompaniesBranches $branch,
        public readonly UserInterface $user,
        public readonly string $title,
        public readonly int $pipeline_stage_id,
        public readonly ?string $description = null,
        public readonly ?int $type_id = null,
        public readonly ?int $status_id = null,
        public readonly ?int $source_id = null,
        public readonly ?int $receiver_id = null,
        public readonly ?string $reason_lost = null,
        public readonly array $custom_fields = [],
    ) {
    }
}
