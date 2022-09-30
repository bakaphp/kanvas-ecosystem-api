<?php

declare(strict_types=1);

namespace Kanvas\UsersGroup\AssociatedApps\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\CompanyGroup\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\UsersGroup\Users\Models\Users;

/**
 * UsersAssociatedApps Model.
 *
 * @property int $users_id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $identify_id
 * @property int $user_active
 * @property string $user_role
 */
class UsersAssociatedApps extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users_associated_apps';

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Users relationship.
     *
     * @return Companies
     */
    public function company() : BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Users relationship.
     *
     * @return Apps
     */
    public function app() : BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }
}
