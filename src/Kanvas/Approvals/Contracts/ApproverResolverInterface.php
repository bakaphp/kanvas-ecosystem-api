<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kanvas\Users\Models\Users;

/**
 * Answers "who may approve this entity" for one step of a policy. Adding a new way of choosing
 * approvers is one implementation registered in the registry — no existing code changes.
 */
interface ApproverResolverInterface
{
    /**
     * Returning an empty collection is a valid answer, not an error — the caller falls back to the
     * policy's fallback resolver and, failing that, flags the request unassigned.
     *
     * @param array<string, mixed> $config
     *
     * @return Collection<int, Users>
     */
    public function resolve(Model $entity, array $config): Collection;
}
