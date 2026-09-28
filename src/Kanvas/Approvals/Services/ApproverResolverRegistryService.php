<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Services;

use Kanvas\Approvals\Contracts\ApproverResolverInterface;
use Kanvas\Approvals\Resolvers\CompanyOwnerApproverResolver;
use Kanvas\Approvals\Resolvers\CustomFieldApproverResolver;
use Kanvas\Approvals\Resolvers\ExplicitUsersResolver;
use Kanvas\Approvals\Resolvers\OrganizationApproverResolver;
use Kanvas\Approvals\Resolvers\RoleApproverResolver;

/**
 * Maps the `resolver` key on a policy step to the class that answers it. A new strategy is one
 * register() call; policies keep referring to resolvers by a stable string, so a rename of the class
 * never invalidates rows already in the database.
 */
class ApproverResolverRegistryService
{
    /** @var array<string, class-string<ApproverResolverInterface>> */
    private static array $resolvers = [
        'organization_approver' => OrganizationApproverResolver::class,
        'role' => RoleApproverResolver::class,
        'explicit_users' => ExplicitUsersResolver::class,
        'custom_field' => CustomFieldApproverResolver::class,
        'company_owner' => CompanyOwnerApproverResolver::class,
    ];

    /**
     * @param class-string<ApproverResolverInterface> $resolver
     */
    public static function register(string $key, string $resolver): void
    {
        self::$resolvers[$key] = $resolver;
    }

    public static function has(string $key): bool
    {
        return isset(self::$resolvers[$key]);
    }

    /**
     * Null for an unknown key rather than an exception: an unrecognised resolver is a misconfigured
     * policy, and that has to surface as a visibly unassigned request rather than blowing up whatever
     * business action happened to trigger the approval.
     */
    public function get(string $key): ?ApproverResolverInterface
    {
        $resolver = self::$resolvers[$key] ?? null;

        return $resolver !== null ? app($resolver) : null;
    }
}
