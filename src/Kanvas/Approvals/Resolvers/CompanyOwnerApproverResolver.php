<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kanvas\Approvals\Contracts\ApproverResolverInterface;
use Kanvas\Users\Models\Users;
use Override;

/**
 * The company's own owner — a policy's last resort, not a first choice.
 *
 * Every other resolver depends on data a tenant can get wrong (a renamed role, an empty channel), and
 * a fallback that itself resolves nobody strands whatever the gate was already holding back.
 * `companies.users_id` cannot be empty.
 */
class CompanyOwnerApproverResolver implements ApproverResolverInterface
{
    #[Override]
    public function resolve(Model $entity, array $config): Collection
    {
        $owner = $entity->company?->user;

        return $owner instanceof Users ? collect([$owner]) : collect();
    }
}
