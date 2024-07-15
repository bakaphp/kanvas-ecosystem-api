<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\DataTransferObject;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

class EntityInteraction extends Data
{
    public function __construct(
        public Model $entity,
        public Model $interactedEntity,
        public string $interaction,
        public ?string $note = null
    ) {
    }
}
