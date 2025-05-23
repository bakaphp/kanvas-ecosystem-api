<?php

declare(strict_types=1);

namespace Kanvas\Apps\Models;

use Baka\Contracts\AppInterface;
use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Baka\Traits\HashTableTrait;
use Baka\Users\Contracts\UserInterface;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Models\BaseModel;
use Kanvas\Roles\Models\Roles;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;

/**
 * Apps Model.
 *
 * @property int $id
 * @property string $key
 * @property string $url
 * @property string $description
 * @property string $domain
 * @property int $is_actived
 * @property int $ecosystem_auth
 * @property int $default_apps_plan_id
 * @property int $payments_active
 * @property int $is_public
 * @property int $domain_based
 * @property string $created_at
 * @property string $updated_at
 * @property int $is_deleted
 */
class Apps extends BaseModel implements AppInterface
{
    use HashTableTrait;
    use Cachable;
    use CanUseWorkflow;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'apps';

    /**
     * The attributes that should not be mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    #[Override]
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->key = $model->key ?? Str::uuid();
        });
    }

    #[Override]
    public static function getByUuid(string $uuid, ?AppInterface $app = null): self
    {
        try {
            return self::where('key', $uuid)
                ->notDeleted()
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException("No app found with id {$uuid}");
        }
    }

    public function companies(): HasManyThrough
    {
        return $this->hasManyThrough(
            Companies::class,
            UserCompanyApps::class,
            'apps_id',
            'id',
            'id',
            'companies_id'
        );
    }

    public function systemModules(): HasMany
    {
        return $this->hasMany(SystemModules::class, 'apps_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(Users::class, 'users_associated_apps', 'apps_id', 'users_id');
    }

    public function keys(): HasMany
    {
        return $this->hasMany(AppKey::class, 'apps_id');
    }

    public function getTotalUsersAttribute(): int
    {
        if (! $totalUser = $this->get('total_users')) {
            $this->set('total_users', $this->users()->count());

            return (int) $this->get('total_users');
        }

        return (int) $totalUser;
    }

    public function getTotalCompaniesAttribute(): int
    {
        if (! $totalCompanies = $this->get('total_companies')) {
            $this->set('total_companies', $this->companies()->count());

            return (int) $this->get('total_companies');
        }

        return (int) $totalCompanies;
    }

    public function getUserKeys(?UserInterface $user = null): Collection
    {
        $user = $user ?? Auth::user();

        return $this->keys()
            ->where('users_id', $user->getId())
            ->get();
    }

    /**
     * Settings relationship.
     */
    public function settings(): hasMany
    {
        return $this->hasMany(Settings::class, 'apps_id');
    }

    /**
     * Roles relationship.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Roles::class, 'apps_id');
    }

    /**
     * Is this app subscription based?
     */
    public function usesSubscriptions(): bool
    {
        return (bool)$this->payments_active;
    }

    /**
     * Set hashtable settings table, userConfig ;).
     */
    protected function createSettingsModel(): void
    {
        $this->settingsModel = new Settings();
    }

    /**
     * Associate company to App.
     */
    public function associateCompany(Companies $company): UserCompanyApps
    {
        return UserCompanyApps::firstOrCreate([
            'apps_id' => $this->getKey(),
            'companies_id' => $company->getKey(),
        ]);
    }

    /**
     * Is active?
     */
    public function isActive(): bool
    {
        return (bool)$this->is_actived;
    }

    /**
     * Those this app use ecosystem login or
     * the its own local login?
     */
    public function usesEcosystemLogin(): bool
    {
        return (bool)$this->ecosystem_auth;
    }

    /**
     * Get th default app currency.
     */
    public function defaultCurrency(): string
    {
        return $this->get('currency');
    }

    /**
     * Returns the default company for this application based on configuration.
     *
     * This function handles two possible application scenarios:
     * 1. Single company application: All users are assigned to one main company
     * 2. Multi-company application: The default company handles all integrations
     * 3. multi-company app with multi company integration where this method is not needed
     *
     * @return Companies The default company entity for this application
     * @throws ModelNotFoundException If no default company is configured
     */
    #[Override]
    public function getAppCompany(): Companies
    {
        $defaultBranchId = $this->get(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());
        $defaultCompanyId = $this->get(AppSettingsEnums::KANVAS_APP_MAIN_COMPANY_ID->getValue());
        $company = null;

        if ($defaultBranchId) {
            $branch = CompaniesBranches::getById($defaultBranchId);
            $company = $branch?->company;
        }

        if (empty($company)) {
            $company = Companies::getById($defaultCompanyId);
        }

        if (! $company) {
            throw new ModelNotFoundException('No default company configured for this application');
        }

        return $company;
    }

    public function setAppCompany(Companies $company): void
    {
        $this->set(AppSettingsEnums::KANVAS_APP_MAIN_COMPANY_ID->getValue(), $company->getKey());
    }

    /**
     * Create user profile for the app
     * @psalm-suppress MixedReturnStatement
     */
    public function associateUser(
        Users $user,
        int $isActive,
        ?int $userRoleId = null,
        ?string $password = null,
        ?string $companyUserIdentifier = null,
        ?string $configuration = null
    ): UsersAssociatedApps {
        return UsersAssociatedApps::firstOrCreate([
            'users_id' => $user->getKey(),
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(), //for now user profile uses company app id 0
            'apps_id' => $this->getKey(),
        ], [
            'users_id' => $user->getKey(),
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'apps_id' => $this->getKey(),
            'identify_id' => $companyUserIdentifier ?? $user->id,
            'user_active' => $isActive,
            'user_role' => $userRoleId ?? $user->roles_id,
            'password' => $password ?? $user->password,
            'configuration' => Str::isJson($configuration) ? json_encode($configuration) : $configuration,
        ]);
    }

    /**
     * Not deleted scope.
     */
    public function scopeUserAssociated(Builder $query): Builder
    {
        $user = Auth::user();

        return $query->select(
            'apps.id',
            'apps.name',
            'apps.description',
            'apps.url',
            'apps.domain',
            'apps.default_apps_plan_id',
            'apps.is_actived',
            'apps.key',
            'apps.payments_active',
            'apps.ecosystem_auth',
            'apps.is_public',
            'apps.domain_based',
            'apps.created_at',
            'apps.updated_at'
        )
            ->join(
                'users_associated_apps',
                'users_associated_apps.apps_id',
                '=',
                'apps.id'
            )
            ->where('users_associated_apps.users_id', '=', $user->getKey())
            ->where('users_associated_apps.is_deleted', '=', StateEnums::NO->getValue())
            ->where('apps.is_deleted', '=', StateEnums::NO->getValue())
            ->groupBy(
                'apps.id',
                'apps.name',
                'apps.description',
                'apps.url',
                'apps.domain',
                'apps.default_apps_plan_id',
                'apps.is_actived',
                'apps.key',
                'apps.payments_active',
                'apps.ecosystem_auth',
                'apps.is_public',
                'apps.domain_based',
                'apps.created_at',
                'apps.updated_at'
            );
    }
}
