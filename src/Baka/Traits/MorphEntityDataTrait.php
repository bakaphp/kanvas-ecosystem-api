<?php

declare(strict_types=1);

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\SystemModules\Models\SystemModules;

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
        $entityNamespace = SystemModules::convertLegacySystemModules($this->entity_namespace);
        
        return $entityNamespace::getById($this->entity_id);
    }
}
