<?php

declare(strict_types=1);

namespace Kanvas\Users\Roles\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Companies\Companies\Models\Companies;
use Kanvas\Models\BaseModel;
use Kanvas\Roles\Models\Roles;
use Kanvas\Users\Users\Models\Users;

/**
 * UserRoles Model.
 *
 * @property int $users_id
 * @property int $apps_id
 * @property int $roles_id
 * @property int $companies_id
 */
class UserRoles extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_roles';

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
     * @return Users
     */
    public function role() : BelongsTo
    {
        return $this->belongsTo(Roles::class, 'roles_id');
    }

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function app() : BelongsTo
    {
        return $this->belongsTo(Apps::class, 'users_id');
    }

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function company() : BelongsTo
    {
        return $this->belongsTo(Companies::class, 'users_id');
    }
}
