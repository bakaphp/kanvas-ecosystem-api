<?php
declare(strict_types=1);

namespace Kanvas\Guild\Rotations\DataTransferObject;

use Spatie\LaravelData\Data;

use Kanvas\Users\Models\Users;
use Kanvas\Guild\Rotations\Models\Rotation;

class RotationUser extends Data
{
    public function __construct(
        public Rotation $rotation,
        public Users $user,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public int $hits = 0,
        public float $percentage = 0,
    ) {
    }

}
