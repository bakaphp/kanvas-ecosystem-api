<?php

declare(strict_types=1);

namespace Kanvas\Apps\Models;

use Baka\Traits\HashTableTrait;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\UserCompanyApps;

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
class Apps extends BaseModel
{
    use HashTableTrait;

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
     * Settings relationship.
     *
     * @return hasMany
     */
    public function settings()
    {
        return $this->hasMany(Settings::class, 'apps_id');
    }

    /**
     * Roles relationship.
     *
     * @return Roles
     */
    public function roles()
    {
        return $this->hasMany(Roles::class, 'apps_id');
    }

    /**
     * Is this app subscription based?
     *
     * @return bool
     */
    public function usesSubscriptions() : bool
    {
        return (bool) $this->payments_active;
    }

    /**
     * Set hashtable settings table, userConfig ;).
     *
     * @return void
     */
    protected function createSettingsModel() : void
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
    public function associateCompany(Companies $company) : UserCompanyApps
    {
        $companyApps = new UserCompanyApps();
        $companyApps->companies_id = $company->id;
        $companyApps->apps_id = $this->id;
        $companyApps->saveOrFail();

        return $companyApps;
    }

    /**
     * Is active?
     *
     * @return bool
     */
    public function isActive() : bool
    {
        return (bool) $this->is_actived;
    }

    /**
     * Those this app use ecosystem login or
     * the its own local login?
     *
     * @return bool
     */
    public function usesEcosystemLogin() : bool
    {
        return (bool) $this->ecosystem_auth;
    }

    /**
     * Get th default app currency.
     *
     * @return string
     */
    public function defaultCurrency() : string
    {
        return $this->get('currency');
    }
}
