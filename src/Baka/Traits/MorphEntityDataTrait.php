<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Model;

trait MorphEntityDataTrait
{
    /**
     * Kanvas morph tables don't follow laravel naming convention.
     * So we add this method to retrieve the entity data.
     *
     * @todo in future version will correct this
     */
    public function entityData(): ?Model
    {
        return $this->entity_namespace::getById($this->entity_id);
    }
}
