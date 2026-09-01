<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kanvas\Approvals\Contracts\ApproverResolverInterface;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Walks the entity to the vendor/customer Organization named in config and reads its approvers:
 * {"relation": "vendor"}. The AP/AR case: which people sign for a given vendor or customer is the
 * organization's own data, so it is answered from organization_approvers rather than from code.
 *
 * Falls back to a legacy single-email custom field for organizations not yet migrated to
 * organization_approvers. The field name is config, not a constant, so this core resolver doesn't
 * have to know about Scribe's enum.
 */
class OrganizationApproverResolver implements ApproverResolverInterface
{
    private const string DEFAULT_LEGACY_FIELD = 'ap_approver_email';

    #[Override]
    public function resolve(Model $entity, array $config): Collection
    {
        $organization = $this->organization($entity, $config);

        if ($organization === null) {
            return collect();
        }

        $approvers = $organization->approvers()
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter(static fn (?Users $user): bool => $user !== null)
            ->unique('id')
            ->values();

        return $approvers->isNotEmpty()
            ? $approvers
            : $this->legacyFallback($organization, $config);
    }

    private function organization(Model $entity, array $config): ?Organization
    {
        if ($entity instanceof Organization) {
            return $entity;
        }

        $relation = trim((string) ($config['relation'] ?? ''));

        if ($relation === '') {
            return null;
        }

        $related = $entity->{$relation} ?? null;

        return $related instanceof Organization ? $related : null;
    }

    /**
     * @return Collection<int, Users>
     */
    private function legacyFallback(Organization $organization, array $config): Collection
    {
        $field = trim((string) ($config['legacy_field'] ?? self::DEFAULT_LEGACY_FIELD));
        $email = trim((string) ($organization->get($field) ?? ''));

        if ($email === '') {
            return collect();
        }

        return Users::query()->where('email', $email)->get();
    }
}
