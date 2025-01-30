<?php
declare(strict_types=1);

namespace Kanvas\Guild\Rotations\DataTransferObject;

use Spatie\LaravelData\Data;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

class Rotation extends Data
{
    public function __construct(
        public Companies $company,
        public Users $user,
        public string $name,
        public ?array $users = null,
        public ?string $id = null
    ) {
    }
}
