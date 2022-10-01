<?php

declare(strict_types=1);

namespace Kanvas\Apps\Models;

use Baka\Traits\HashTableTrait;
use Kanvas\Apps\Factories\AppsFactory;
use Kanvas\AppsGroup\Roles\Models\Roles;
use Kanvas\AppsGroup\Settings\Models\Settings;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\UsersGroup\CompanyApps\Models\UserCompanyApps;

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
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return AppsFactory::new();
    }

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
     * Associate company to App
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
}
