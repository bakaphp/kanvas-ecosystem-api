<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Model;

trait MorphEntityDataTrait
{
    /**
     * Kanvas morph tables don't follow laravel convention for naming.
     * So we add this method to retrieve the entity data.
     *
     * @todo in future version we should change the naming convention for the tables
     * to follow laravel convention.
     *
     * @return Model|null
     */
    public function entityData(): ?Model
    {
        return $this->entity_namespace::getById($this->entity_id);
    }
}
