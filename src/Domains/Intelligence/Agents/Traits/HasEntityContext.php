<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Illuminate\Database\Eloquent\Model;

/**
 * Injects the record in scope (the lead/order/message the agent is working on) into a tool via a
 * setter — not the constructor, because Apps/Companies/Users all extend Model, so a typed ?Model
 * constructor param would mis-bind to the app. Shared by both the Neuron and Laravel tool surfaces;
 * the agent fills it when it builds its tool list.
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
