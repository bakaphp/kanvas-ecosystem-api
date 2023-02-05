<?php
declare(strict_types=1);

namespace Kanvas\Social\Interactions\DataTransferObject;

use Spatie\LaravelData\Data;

class LikeEntityInput extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public string $entity_id,
        public string $entity_namespace,
        public string $interacted_entity_id,
        public string $interacted_entity_namespace,
    ) {
    }
}
