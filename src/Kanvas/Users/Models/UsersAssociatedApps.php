<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Casts\Json;
use Baka\Support\Str;
use Baka\Traits\SoftDeletesTrait;
use Baka\Users\Contracts\UserAppInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Auth\Contracts\Authenticatable;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Enums\StatusEnums;
use Kanvas\Users\Observers\UsersAssociatedAppsObserver;
use Override;

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
 * @property int $is_verified
 * @property int $user_recover_code
 * @property string $two_step_phone_number
 * @property string $email_verified_at
 * @property string $phone_verified_at
 * @property int|null $people_id
 * @property string|null $stripe_id
 * @property  string $timezone
 * @property int $is_deleted
 */
#[ObservedBy([UsersAssociatedAppsObserver::class])]
class UsersAssociatedApps extends BaseModel implements Authenticatable, UserAppInterface
{
    // use SoftDeletesTrait;
    // public const DELETED_AT = 'is_deleted';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users_associated_apps';

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
        'is_verified',
        'two_step_phone_number',
        'unread_notifications_count',
        'unread_notifications_count',
        'phone_verified_at',
        'email_verified_at',
        'timezone',
        'people_id',
        'stripe_id',
    ];

    protected $casts = [
        'configuration' => Json::class,
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
        'welcome' => 'boolean',
        'unread_notifications_count' => 'integer',
        'total_messages_count' => 'integer',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'user_role');
    }

    public function people(): HasOne
    {
        return $this->hasOne(People::class, 'id', 'people_id');
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
    #[Override]
    public function set(string $key, mixed $value): void
    {
        $this->configuration[$key] = $value;
        $this->saveOrFail();
    }

    /**
     * Get a specific config value for the specific user.
     */
    #[Override]
    public function get(string $key): mixed
    {
        return $this->configuration[$key] ?? null;
    }

    #[Override]
    public function isActive(): bool
    {
        return ! $this->is_deleted && $this->is_active;
    }

    #[Override]
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
    #[Override]
    public function getKey()
    {
        return $this->users_id . $this->apps_id . $this->companies_id;
    }
}
