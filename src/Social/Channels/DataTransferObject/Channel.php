<?php

declare (strict_types=1);

namespace Kanvas\Social\Channels\DataTransferObject;

use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Channel extends Data
{
    public function __construct(
        public Users $users,
        public string $name = '',
        public string $description = '',
        public string $entity_id,
        public string $entity_namespace,
    ) {
    }
}
