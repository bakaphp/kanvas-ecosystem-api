<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kanvas\Approvals\Contracts\ApproverResolverInterface;
use Kanvas\Users\Models\Users;
use Override;

/**
 * An approver email held in a custom field, on the entity itself or on a relation of it:
 * {"field": "ap_approver_email", "relation": "vendor"}.
 *
 * The simplest option, and the migration path for tenants whose approver is still a single address
 * rather than a linked Kanvas user.
 */
class CustomFieldApproverResolver implements ApproverResolverInterface
{
    #[Override]
    public function resolve(Model $entity, array $config): Collection
    {
        $field = trim((string) ($config['field'] ?? ''));

        if ($field === '') {
            return collect();
        }

        $holder = $this->holder($entity, $config);

        if ($holder === null || ! method_exists($holder, 'get')) {
            return collect();
        }

        $email = trim((string) ($holder->get($field) ?? ''));

        if ($email === '') {
            return collect();
        }

        return Users::query()->where('email', $email)->get();
    }

    private function holder(Model $entity, array $config): ?Model
    {
        $relation = trim((string) ($config['relation'] ?? ''));

        if ($relation === '') {
            return $entity;
        }

        $related = $entity->{$relation} ?? null;

        return $related instanceof Model ? $related : null;
    }
}
