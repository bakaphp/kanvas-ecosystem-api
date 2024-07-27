<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Rotations\Models\Rotation;
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
        public ?Rotation $rotation = null
    ) {
    }
}
