<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kanvas\Approvals\Contracts\ApproverResolverInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Repositories\UsersRepository;
use Override;

/**
 * Everyone holding a Bouncer role in the entity's company: {"role": "Finance"}.
 *
 * A role that does not exist for this tenant resolves to nobody rather than throwing — a
 * misconfigured policy must surface as an unassigned request the operator can see, not as an
 * exception that aborts whatever was being created.
 */
class RoleApproverResolver implements ApproverResolverInterface
{
    #[Override]
    public function resolve(Model $entity, array $config): Collection
    {
        $role = trim((string) ($config['role'] ?? ''));

        if ($role === '') {
            return collect();
        }

        $app = $entity->app ?? app(Apps::class);
        $company = $entity->company ?? null;

        if ($company === null) {
            return collect();
        }

        try {
            return UsersRepository::getCompanyAppUserByRole($company, $app, $role)->get();
        } catch (ModelNotFoundException) {
            return collect();
        }
    }
}
