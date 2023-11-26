<?php

declare(strict_types=1);

namespace Kanvas\Social\Reactions\DataTransferObject;

use Kanvas\Social\Reactions\Models\Reaction as ReactionsModel;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class UserReaction extends Data
{
    public function __construct(
        public Users $users,
        public ReactionsModel $reactions,
        public string $entity_id,
        public SystemModules $system_modules,
    ) {
    }
}
