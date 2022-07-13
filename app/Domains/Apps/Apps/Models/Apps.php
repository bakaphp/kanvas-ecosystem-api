<?php

declare(strict_types=1);

namespace Kanvas\Apps\Apps\Models;

use Kanvas\Apps\Apps\Factories\AppsFactory;
use Kanvas\Apps\Roles\Models\Roles;
use Kanvas\Apps\Settings\Models\Settings;
use Kanvas\Models\BaseModel;

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
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'apps';

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
}
