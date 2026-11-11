<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Throwable;

/**
 * "Is this person the company's owner or one of its admins" — the authority both the approver
 * self-assign override and approval-policy edits are gated on.
 *
 * Membership is checked before the role: `isAdmin()` reads Bouncer state that is app-scoped, so on its
 * own it would let an admin of one company act on another company's approvals.
 */
class CompanyAuthorityService
{
    public const string OWNER = 'owner';
    public const string ADMIN = 'admin';

    public static function authorityOf(Companies $company, Users $user): ?string
    {
        if ($company->isOwner($user)) {
            return self::OWNER;
        }

        // belongsToCompany throws rather than returning false for a non-member, and a stranger has to
        // come back as "no authority", not as a bare ModelNotFoundException out of an authorization check.
        try {
            if (! UsersRepository::belongsToCompany($user, $company)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $user->isAdmin() ? self::ADMIN : null;
    }

    /**
     * @throws AuthorizationException
     */
    public static function assertOwnerOrAdmin(Companies $company, Users $user, string $doingWhat): string
    {
        $authority = self::authorityOf($company, $user);

        if ($authority === null) {
            throw new AuthorizationException("Only the company's owner or an admin can {$doingWhat}.");
        }

        return $authority;
    }
}
