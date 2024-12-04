<?php

declare(strict_types=1);

namespace Kanvas\Exceptions;

use Baka\Exceptions\LightHouseCustomException;
use Illuminate\Database\Eloquent\Model;

class EntityNotIntegratedException extends LightHouseCustomException
{
    public function __construct(Model $entity, string $service)
    {
        parent::__construct(
            sprintf(
                'Entity %s not integrated with %s',
                get_class($entity) . ' ' . $entity->id,
                $service
            )
        );
    }
}
