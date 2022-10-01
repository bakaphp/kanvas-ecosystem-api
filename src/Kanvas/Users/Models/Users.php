<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Traits\HashTableTrait;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Kanvas\Auth\Traits\HasApiTokens;
use Kanvas\Companies\Models\Companies;
use Kanvas\CompaniesBranches\Models\CompaniesBranches;
use Kanvas\Roles\Models\Roles;
use Kanvas\Traits\PermissionsTrait;
use Kanvas\Traits\UsersAssociatedTrait;
use Kanvas\Users\Factories\UsersFactory;
use Kanvas\UsersGroup\Config\Models\UserConfig;

/**
 * Apps Model.
 *
 * @property string $uuid
 * @property string $email
 * @property string $password
 * @property string $firstname
 * @property string $lastname
 * @property string $description
 * @property int $roles_id
 * @property string $displayname
 * @property int $default_company
 * @property int $default_company_branch
 * @property int $city_id
 * @property int $state_id
 * @property int $country_id
 * @property string $registered
 * @property string $lastvisit
 * @property string $sex
 * @property string $dob
 * @property string $timezone
 * @property string $phone_number
 * @property string $cell_phone_number
 * @property int $profile_privacy
 * @property string $profile_image
 * @property string $profile_header
 * @property string $profile_header_mobile
 * @property int $user_active
 * @property int $user_login_tries
 * @property int $user_last_login_try
 * @property int $session_time
 * @property int $session_page
 * @property int $welcome
 * @property string $user_activation_key
 * @property string $user_activation_email
 * @property string $user_activation_forgot
 * @property string $language
 * @property int $karma
 * @property int $votes
 * @property int $votes_points
 * @property int $banned
 * @property string $location
 * @property int $system_modules_id
 * @property int $status
 * @property string $address_1
 * @property string $address_2
 * @property string $zip_code
 * @property int $user_recover_code
 * @property int $is_deleted
 */
class Users extends Authenticatable
{
    use HashTableTrait;
    use UsersAssociatedTrait;
    //use PermissionsTrait;
    use Notifiable;
    use HasFactory;
    use HasApiTokens;

    protected ?string $defaultCompanyName = null;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return UsersFactory::new();
    }

    /**
     * Default Company relationship.
     *
     * @return hasMany
     */
    public function defaultCompany() : HasOne
    {
        return $this->hasOne(Companies::class, 'id', 'default_company');
    }

    /**
     * Companies relationship.
     *
     * @return hasMany
     */
    public function companies() : HasMany
    {
        return $this->hasMany(Companies::class, 'users_id');
    }

    /**
     * CompaniesBranches relationship.
     *
     * @return hasMany
     */
    public function branches() : HasMany
    {
        return $this->hasMany(CompaniesBranches::class, 'users_id');
    }

    /**
     * Role relationship.
     *
     * @return void
     */
    public function role() : HasOne
    {
        return $this->hasOne(Roles::class, 'id', 'roles_id');
    }

    /**
     * Get User's email.
     *
     * @return string
     */
    public function getEmail() : string
    {
        return $this->email;
    }

    /**
     * Get user by there email address.
     *
     * @return self
     */
    public static function getByEmail(string $email) : self
    {
        $user = self::where(
            [
                'email' => $email,
                'is_deleted' => 0
            ]
        )->first();

        if (!$user) {
            throw new Exception('No User Found');
        }

        return $user;
    }

    /**
     * is the user active?
     *
     * @return bool
     */
    public function isActive() : bool
    {
        return (bool) $this->user_active;
    }

    /**
     * Determine if a user is banned.
     *
     * @return bool
     */
    public function isBanned() : bool
    {
        return !$this->isActive() && $this->banned === 'Y';
    }

    /**
     * Set hashtable settings table, userConfig ;).
     *
     * @return void
     */
    protected function createSettingsModel() : void
    {
        $this->settingsModel = new UserConfig();
    }

    /**
     * A company owner is the first person that register this company
     * This only ocurred when signing up the first time, after that all users invites
     * come with a default_company id attached.
     *
     * @return bool
     */
    public function isFirstSignup() : bool
    {
        return empty($this->default_company);
    }

    /**
     * What the current company the users is logged in with
     * in this current session?
     *
     * @return int
     */
    public function currentCompanyId() : int
    {
        return  (int) $this->get(Companies::cacheKey());
    }
}
