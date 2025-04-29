<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Channel extends Data
{
    public function __construct(
        public Apps $apps,
        public Companies $companies,
        public Users $users,
        public string|int $entity_id,
        public string $entity_namespace,
        public string $name = '',
        public string $description = '',
        public ?string $slug = null
    ) {
    }
}
