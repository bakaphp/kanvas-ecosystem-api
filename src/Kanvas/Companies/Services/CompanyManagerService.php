<?php

declare(strict_types=1);

namespace Kanvas\Companies\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

/**
 * One place to answer "who manages this company?".
 *
 * The role is the source of truth; the `company_manager` setting is the list tenants kept before
 * roles existed, still merged in (deduped by user id) until it can be retired. A company whose app
 * never created the role has no managers — a normal state, not a fault, so the lookup never throws.
 *
 * The default role is `NotificationAllEngagement`, overridable per app with the
 * `notification_manager_role` setting. Never pass the literal `'Manager'`: the roles table holds
 * `Managers` (plural) on 128 apps and the singular on two — see `Connectors/Yusen/CLAUDE.md`.
 */
class CompanyManagerService
{
    public const string LEGACY_MANAGERS_SETTING = 'company_manager';
    public const string NOTIFICATION_MANAGER_ROLE = RolesEnums::NOTIFICATION_MANAGER->value;

    public function __construct(
        protected readonly CompanyInterface $company,
        protected readonly AppInterface $app,
    ) {
    }

    public function getManagers(?string $roleName = null): Collection
    {
        return $this->getManagersByRole($roleName)->merge($this->getLegacyManagers());
    }

    public function getManagersByRole(?string $roleName = null): Collection
    {
        try {
            $appNotificationManagerRole = $this->app->get('notification_manager_role');
            $role = $roleName ?? $appNotificationManagerRole ?? self::NOTIFICATION_MANAGER_ROLE;

            return $this->currentMembersOnly(
                UsersRepository::getCompanyAppUserByRole($this->company, $this->app, $role)
            )->get();
        } catch (ModelNotFoundException) {
            return new Collection();
        }
    }

    public function getLegacyManagers(): Collection
    {
        $managers = $this->company->get(self::LEGACY_MANAGERS_SETTING);

        if (! is_array($managers) || $managers === []) {
            return new Collection();
        }

        return UsersRepository::findUsersByArray($managers, $this->app)
            ->reject(fn (Users $user) => (bool) $user->is_deleted)
            ->values();
    }

    /**
     * Roles are app-wide, so only the membership row says whether this user still belongs to the
     * company. A removed, deactivated or banned member must not keep receiving its reports — those
     * carry customer PII (ID-verification results, per-rep numbers).
     */
    private function currentMembersOnly(Builder $query): Builder
    {
        return $query->where('users_associated_apps.is_deleted', 0)
            ->where('users_associated_apps.is_active', 1)
            ->where(
                fn (Builder $membership) => $membership->whereNull('users_associated_apps.banned')
                    ->orWhere('users_associated_apps.banned', 0)
            )
            ->notDeleted();
    }
}
