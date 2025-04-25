<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Spatie\LaravelData\Data;

class LeadReceiver extends Data
{
    /**
     * __construct.
     */
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompaniesBranches $branch,
        public readonly UserInterface $user,
        public readonly UserInterface $agent,
        public string $name,
        public string $source,
        public bool $isDefault = false,
        public int $lead_sources_id = 0,
        public int $lead_types_id = 0,
        public string|array|null $template = null,
        public ?LeadRotation $rotation = null
    ) {
    }
}
