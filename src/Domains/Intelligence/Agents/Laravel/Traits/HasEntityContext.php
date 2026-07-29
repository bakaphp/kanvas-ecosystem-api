<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Traits;

use Illuminate\Database\Eloquent\Model;

trait HasEntityContext
{
    protected ?Model $entity = null;

    public function withEntity(?Model $entity): static
    {
        $this->entity = $entity;

        return $this;
    }
}
