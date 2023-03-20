<?php

declare(strict_types=1);

namespace Kanvas\Apps\Models;

use Baka\Contracts\AppInterface;
use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Baka\Traits\HashTableTrait;
use Baka\Traits\UuidTrait;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Models\BaseModel;
use Kanvas\Roles\Models\Roles;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;

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

    /**
     * Boot function from Laravel.
     */
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->key = $model->key ?? Str::uuid();
        });
    }

    /**
     * Settings relationship.
     *
     * @return hasMany
     */
    public function settings(): hasMany
    {
        return $this->hasMany(Settings::class, 'apps_id');
    }

    /**
     * Roles relationship.
     *
     * @return hasMany
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Roles::class, 'apps_id');
    }

    /**
     * Is this app subscription based?
     *
     * @return bool
     */
    public function usesSubscriptions(): bool
    {
        return (bool)$this->payments_active;
    }

    /**
     * Set hashtable settings table, userConfig ;).
     *
     * @return void
     */
    protected function createSettingsModel(): void
    {
        $this->settingsModel = new Settings();
    }

    /**
     * Associate company to App.
     *
     * @param Companies $company
     *
     * @return UserCompanyApps
     */
    public function associateCompany(Companies $company): UserCompanyApps
    {
        return UserCompanyApps::firstOrCreate([
            'apps_id' => $this->id,
            'companies_id' => $company->getKey(),
        ]);
    }

    /**
     * Is active?
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->is_actived;
    }

    /**
     * Those this app use ecosystem login or
     * the its own local login?
     *
     * @return bool
     */
    public function usesEcosystemLogin(): bool
    {
        return (bool)$this->ecosystem_auth;
    }

    /**
     * Get th default app currency.
     *
     * @return string
     */
    public function defaultCurrency(): string
    {
        return $this->get('currency');
    }

    /**
     * Associate user to the app.
     *
     * @param Users $user
     * @param Apps $app
     * @param int $isActive
     * @param int|null $userRoleId
     * @param string|null $password
     * @param string|null $companyUserIdentifier
     *
     * @return UsersAssociatedApps
     */
    public function associateUser(
        Users $user,
        int $isActive,
        ?int $userRoleId = null,
        string $password = null,
        string $companyUserIdentifier = null,
        string $configuration = null
    ): UsersAssociatedApps {
        return UsersAssociatedApps::firstOrCreate([
            'users_id' => $user->getKey(),
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'apps_id' => $this->getKey(),
        ], [
            'users_id' => $user->getKey(),
            'companies_id' => AppEnums::GLOBAL_COMPANY_ID->getValue(),
            'apps_id' => $this->getKey(),
            'identify_id' => $companyUserIdentifier ?? $user->id,
            'user_active' => $isActive,
            'user_role' => $userRoleId ?? $user->roles_id,
            'password' => $password,
            'configuration' => Str::isJson($configuration) ? json_encode($configuration) : $configuration,
        ]);
    }

    /**
     * Not deleted scope.
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeUserAssociated(Builder $query): Builder
    {
        $user = Auth::user();

        return $query->select('apps.*')
            ->join(
                'users_associated_apps',
                'users_associated_apps.apps_id',
                '=',
                'apps.id'
            )
            ->where('users_associated_apps.users_id', '=', $user->getKey())
            ->where('users_associated_apps.is_deleted', '=', StateEnums::NO->getValue())
            ->where('apps.is_deleted', '=', StateEnums::NO->getValue())
            ->groupBy('users_associated_apps.apps_id');
    }
}
