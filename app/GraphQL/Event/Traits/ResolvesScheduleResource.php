<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\SystemModules\Models\SystemModules;

trait ResolvesScheduleResource
{
    private function getResource(string $resourceType, int|string $resourceId): Model
    {
        $entityClass = SystemModules::getSystemModuleNameSpaceBySlug($resourceType);

        return $entityClass::getById($resourceId);
    }
}
