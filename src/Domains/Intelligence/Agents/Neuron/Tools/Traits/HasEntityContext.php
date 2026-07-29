<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Database\Eloquent\Model;

/**
 * Injects the record in scope (the lead/order/message the agent is working on) into a Neuron tool via a
 * setter — not the constructor, because Apps/Companies/Users all extend Model, so a typed ?Model param
 * would mis-bind to the app. Filled by MergesRegisteredTools::fillKanvasContext.
 */
trait HasEntityContext
{
    protected ?Model $entity = null;

    public function withEntity(?Model $entity): static
    {
        $this->entity = $entity;

        return $this;
    }
}
