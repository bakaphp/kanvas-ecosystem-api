<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Users\Contracts\UserAppInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Users relationship.
     *
     * @return Companies
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Users relationship.
     *
     * @return Apps
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
        if (Str::isJson($this->configuration)) {
            $configuration = json_decode($this->configuration, true);
            $configuration[$key] = $value;
            $this->configuration = json_encode($configuration);
        } else {
            $this->configuration = json_encode([
                $key => $value,
            ]);
        }

        $this->saveOrFail();
    }

    /**
     * Get a specific config value for the specific user.
     */
    public function get(string $key): mixed
    {
        if (Str::isJson($this->configuration)) {
            $configuration = json_decode($this->configuration, true);

            return $configuration[$key] ?? null;
        }

        return null;
    }

    /**
     * Register an user into a new app with a password for the login.
     */
    public static function registerUserApp(Users $user, string $password): UsersAssociatedApps
    {
        return self::firstOrCreate([
            'users_id' => $user->getKey(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $user->default_company,
        ], [
            'identify_id' => $user->getKey(),
            'password' => $password,
            'user_active' => StatusEnums::ACTIVE->getValue(),
            'user_role' => $user->roles_id ?? AppEnums::DEFAULT_ROLE_ID->getValue(),
            'displayname' => $user->displayname,
            'lastvisit' => date('Y-m-d H:i:s'),
            'session_time' => time(),
            'welcome' => 0,
            'user_login_tries' => 0,
            'user_last_login_try' => 0,
            'user_activation_key' => Hash::make(time()),
            'banned' => StateEnums::NO->getValue(),
            'status' => StatusEnums::ACTIVE->getValue(),
        ]);
    }

    /**
     * Check if the user is on the current app.
     */
    public static function userOnApp(Users $user): bool
    {
        return (bool) self::where('apps_id', app(Apps::class)->getId())
        ->where('users_id', $user->getKey())
        ->count() > 0;
    }
}
