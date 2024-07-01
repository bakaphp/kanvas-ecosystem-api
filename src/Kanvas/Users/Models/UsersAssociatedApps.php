<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Support\Str;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Traits\SoftDeletesTrait;
use Baka\Users\Contracts\UserAppInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Auth\Contracts\Authenticatable;
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
 * @property ?string $firstname
 * @property ?string $lastname
 * @property ?string $email
 * @property int $user_active
 * @property string $is_active
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
 * @property string $two_step_phone_number
 * @property string $email_verified_at
 * @property string $phone_verified_at
 * @property int $is_deleted
 */
class UsersAssociatedApps extends BaseModel implements Authenticatable, UserAppInterface
{
    use HasCompositePrimaryKeyTrait;
    // use SoftDeletesTrait;
    // public const DELETED_AT = 'is_deleted';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users_associated_apps';

    protected $primaryKey = [
        'users_id',
        'apps_id',
        'companies_id',
    ];

    protected $fillable = [
        'users_id',
        'apps_id',
        'firstname',
        'lastname',
        'email',
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
        'two_step_phone_number',
        'phone_verified_at',
        'email_verified_at',
    ];

    protected $casts = [
        'configuration' => 'array',
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
        'welcome' => 'boolean'
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'user_role');
    }

    public function deActive(): bool
    {
        $this->is_active = 0;

        return $this->saveOrFail();
    }

    public function active(): bool
    {
        $this->is_active = 1;

        return $this->saveOrFail();
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
        return ! $this->is_deleted && $this->is_active;
    }

    public function isBanned(): bool
    {
        return $this->banned === StatusEnums::ACTIVE->getValue();
    }

    public function getTwoStepPhoneNumber(): string
    {
        return Str::sanitizePhoneNumber($this->two_step_phone_number);
    }

    /**
     * since we store this entity for user role of the given company
     * we need to create a composite key
     * @override
     */
    public function getKey()
    {
        return $this->users_id . $this->apps_id . $this->companies_id;
    }
}
