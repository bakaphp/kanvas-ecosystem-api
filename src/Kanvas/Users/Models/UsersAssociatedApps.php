<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Users\Contracts\UserAppInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Contracts\Authenticatable;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Enums\StatusEnums;

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
 * @property string $displayname
 * @property string $lastvisit
 * @property int $user_login_tries
 * @property int $user_last_login_try
 * @property string $user_activation_key
 * @property string $user_activation_forgot
 * @property int $banned
 * @property int $status
 * @property int $user_recover_code
 */
class UsersAssociatedApps extends BaseModel implements Authenticatable, UserAppInterface
{
    use HasCompositePrimaryKeyTrait;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users_associated_apps';

    protected $primaryKey = [
        'users_id',
        'apps_id',
    ];

    protected $fillable = [
        'users_id',
        'apps_id',
        'companies_id',
        'identify_id',
        'password',
        'user_role',
        'user_active',
        'displayname',
        'lastvisit',
        'session_time',
        'welcome',
        'user_login_tries',
        'user_last_login_try',
        'user_activation_key',
        'banned',
        'status',
    ];

    protected $casts = [
        'configuration' => 'array',
    ];

    /**
     * Users relationship.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Users relationship.
     *
     * @return BelongsTo
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'user_role');
    }

    /**
     * Users relationship.
     *
     * @return BelongsTo
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * Set a new config value for the specific user.
     */
    public function set(string $key, mixed $value): void
    {
        $this->configuration[$key] = $value;
        $this->saveOrFail();
    }

    /**
     * Get a specific config value for the specific user.
     */
    public function get(string $key): mixed
    {
        return $this->configuration[$key] ?? null;
    }

    public function isActive(): bool
    {
        return $this->user_active === StatusEnums::ACTIVE->getValue();
    }

    public function isBanned(): bool
    {
        return $this->banned === StatusEnums::ACTIVE->getValue();
    }
}
