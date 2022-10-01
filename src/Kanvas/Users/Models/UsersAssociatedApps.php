<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * UsersAssociatedApps Model.
 *
 * @property int $users_id
 * @property int $apps_id
 * @property int $companies_id
 * @property ?string $identify_id
 * @property ?string $password
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

    protected $fillable = [
        'users_id',
        'apps_id',
        'roles_id',
        'companies_id',
        'identify_id',
        'password',
        'user_role',
    ];

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
