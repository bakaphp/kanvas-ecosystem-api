<?php

declare(strict_types=1);
namespace Kanvas\Users\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;
use Baka\Traits\HasCompositePrimaryKeyTrait;

/**
 * Companies Model.
 *
 * @property int $users_id
 * @property string $name
 * @property string $value
 */
class UserConfig extends BaseModel
{
    use HasCompositePrimaryKeyTrait;
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_config';
    protected $primaryKey = ['users_id', 'name '];
    public $incrementing = false;
    protected $guarded = [];

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
