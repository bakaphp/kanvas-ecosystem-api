<?php

declare(strict_types=1);

namespace Kanvas\Apps\Models;

use Baka\Support\Str;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * AppPlan Model.
 *
 * @property string $client_id
 * @property string $name
 * @property string $client_secret_id
 * @property int $apps_id
 * @property int $users_id
 * @property string $scope
 * @property string $last_used_date
 * @property ?string $expires_at = null
 * @property string $created_at
 * @property ?string $updated_at = null
 * @property int $is_deleted
 */
class AppKey extends BaseModel
{
    use UuidTrait;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'apps_keys';

    /**
     * The attributes that should not be mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    protected $primaryKey = 'client_id';
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Boot function from laravel.
     *
     * @return void
     */
    public static function bootUuidTrait()
    {
        static::creating(function ($model) {
            $model->client_id = $model->client_id ?? Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function hasExpired(): bool
    {
        return ! empty($this->expires_at) && $this->expires_at < date('Y-m-d H:i:s');
    }
}
