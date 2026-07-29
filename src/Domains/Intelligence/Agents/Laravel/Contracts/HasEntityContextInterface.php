<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in contract for a Laravel tool that needs the record currently in scope (the lead/order/message
 * the agent is working on). Kept separate from KanvasToolInterface so the many app+company-only tools
 * are untouched; KanvasLaravelAgent::tools() calls withEntity() only on tools that implement this.
 */
interface HasEntityContextInterface
{
    public function withEntity(?Model $entity): static;
}
