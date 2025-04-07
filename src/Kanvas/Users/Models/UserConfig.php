<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Casts\Json;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;

/**
 * Companies Model.
 *
 * @property int $users_id
 * @property string $name
 * @property string $value
 * @property int|bool $is_public
 */
class UserConfig extends BaseModel
{
    use HasCompositePrimaryKeyTrait;

    protected $table = 'user_config';

    protected $primaryKey = ['users_id', 'name'];

    public $incrementing = false;

    protected $guarded = [];

    protected $attributes = [
    ];

    protected $casts = [
        'value' => Json::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
