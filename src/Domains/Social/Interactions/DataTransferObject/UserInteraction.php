<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\DataTransferObject;

use Kanvas\Social\Interactions\Models\Interactions;

use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class UserInteraction extends Data
{
    public function __construct(
        public Users $user,
        public Interactions $interaction,
        public string $entity_id,
        public string $entity_namespace,
        public ?string $notes = null,
    ) {
    }
}
