<?php

declare(strict_types=1);

namespace Kanvas\Companies\Support;

use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Contracts\ReadableAtLocation;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Users\Models\Users;
use WeakMap;

/**
 * The X-Kanvas-Location header names a store branch. Any shopper may browse and buy at it, but it
 * only becomes the user's own working company when they are a member of it, an owner of the app,
 * or the user of the app key the request came in with.
 */
final class LocationContext
{
    /** @var WeakMap<CompaniesBranches, array<int, bool>>|null */
    private static ?WeakMap $access = null;

    public static function branch(): ?CompaniesBranches
    {
        return app()->bound(CompaniesBranches::class) ? app(CompaniesBranches::class) : null;
    }

    public static function company(): ?Companies
    {
        return self::branch()?->company;
    }

    public static function branchFor(UserInterface $user): ?CompaniesBranches
    {
        $branch = self::branch();

        if ($branch === null) {
            return null;
        }

        self::$access ??= new WeakMap();
        $granted = self::$access[$branch] ?? [];
        $granted[$user->getId()] ??= self::canActIn($user, $branch->company);
        self::$access[$branch] = $granted;

        return $granted[$user->getId()] ? $branch : null;
    }

    /**
     * Shopping at a store the user is not a member of: they reach only their own records there.
     */
    public static function isShopperOnly(UserInterface $user): bool
    {
        return self::branch() !== null && self::branchFor($user) === null;
    }

    /**
     * The store a shopper is buying from: the header's company when there is one, otherwise the
     * user's own company.
     */
    public static function shoppingCompany(UserInterface $user): CompanyInterface
    {
        return self::company() ?? $user->getCurrentCompany();
    }

    public static function shoppingBranch(UserInterface $user): CompaniesBranches
    {
        return self::branch() ?? $user->getCurrentBranch();
    }

    public static function companyToScope(object $model): CompanyInterface
    {
        $user = auth()->user();

        return $model instanceof ReadableAtLocation
            ? self::shoppingCompany($user)
            : $user->getCurrentCompany();
    }

    /**
     * @param class-string $modelClass
     */
    public static function readableCompany(string $modelClass, UserInterface $user): ?Companies
    {
        return is_a($modelClass, ReadableAtLocation::class, true)
            ? self::company()
            : self::branchFor($user)?->company;
    }

    private static function canActIn(UserInterface $user, CompanyInterface $company): bool
    {
        if (app()->bound(AppKey::class) && app(AppKey::class)->isHeldBy((int) $user->getId())) {
            return true;
        }

        return $user instanceof Users && ($user->isMemberOf($company) || $user->isOwnerOf(app(Apps::class)));
    }
}
