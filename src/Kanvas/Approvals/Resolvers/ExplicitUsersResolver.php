<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kanvas\Approvals\Contracts\ApproverResolverInterface;
use Kanvas\Users\Models\Users;
use Override;

/**
 * A fixed list of user ids on the step: {"users_id": [12, 34]}.
 */
class ExplicitUsersResolver implements ApproverResolverInterface
{
    #[Override]
    public function resolve(Model $entity, array $config): Collection
    {
        $ids = array_filter(array_map('intval', (array) ($config['users_id'] ?? [])));

        if ($ids === []) {
            return collect();
        }

        return Users::query()->whereIn('id', $ids)->get();
    }
}
