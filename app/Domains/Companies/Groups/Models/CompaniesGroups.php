<?php

declare(strict_types=1);

namespace Kanvas\Companies\Groups\Models;

use Kanvas\Companies\Associations\Models\Associations;
use Kanvas\Companies\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Users\Models\Users;

/**
 * CompaniesGroups Model.
 *
 * @property int $apps_id
 * @property int $users_id
 * @property int $stripe_id
 * @property string $uuid
 * @property string $name
 * @property int $is_default
 * @property string $country_code
 */
class CompaniesGroups extends BaseModel
{

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies_groups';

    /**
     * CompaniesBranches relationship.
     *
     * @return hasMany
     */
    public function companiesAssoc()
    {
        return $this->hasMany(Associations::class, 'companies_groups_id');
    }

    /**
     * Companies relationship.
     *
     * @return hasMany
     */
    public function companies()
    {
        return $this->belongsToMany(Companies::class, 'companies_associations');
    }

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function user() : Users
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
