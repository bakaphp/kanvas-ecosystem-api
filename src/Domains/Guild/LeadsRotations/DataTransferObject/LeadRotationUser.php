<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadsRotations\DataTransferObject;

use Spatie\LaravelData\Data;

use Kanvas\Users\Models\Users;
use Kanvas\Guild\LeadsRotations\Models\LeadRotation;

class LeadRotationUser extends Data
{
    public function __construct(
        public LeadRotation $rotation,
        public Users $user,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public int $hits = 0,
        public float $percentage = 0,
    ) {
    }
}
