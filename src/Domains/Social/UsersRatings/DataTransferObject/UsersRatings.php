<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersRatings\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class UsersRatings extends Data
{
    public function __construct(
        public Apps $app,
        public Users $user,
        public Companies $company,
        public SystemModules $systemModule,
        public int $entityId,
        public float $rating,
        public ?string $comment = null
    ) {
    }
}
