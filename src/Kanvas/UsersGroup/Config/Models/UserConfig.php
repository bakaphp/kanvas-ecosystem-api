<?php

declare(strict_types=1);

namespace Kanvas\UsersGroup\Config\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Companies Model.
 *
 * @property int $users_id
 * @property string $name
 * @property string $value
 */
class UserConfig extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_config';

    protected $attributes = [
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
}
