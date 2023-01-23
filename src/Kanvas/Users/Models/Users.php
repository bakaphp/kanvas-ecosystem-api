<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Support\Str;
use Baka\Traits\HashTableTrait;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Contracts\Authenticatable as ContractsAuthenticatable;
use Kanvas\Auth\Traits\HasApiTokens;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\StateEnums;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Notifications\Models\Notifications;
use Kanvas\Roles\Models\Roles;
use Kanvas\Traits\KanvasModelTrait;
use Kanvas\Traits\PermissionsTrait;
use Kanvas\Traits\UsersAssociatedTrait;
use Kanvas\Users\Factories\UsersFactory;
use Silber\Bouncer\Database\HasRolesAndAbilities;

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
class Users extends Authenticatable implements UserInterface, ContractsAuthenticatable
{
    use HashTableTrait;
    use UsersAssociatedTrait;
    //use PermissionsTrait;
    use Notifiable;
    use HasFactory;
    use HasApiTokens;
    use HasRolesAndAbilities;
    use HasFilesystemTrait;
    use KanvasModelTrait;

    protected ?string $defaultCompanyName = null;
    protected $guarded = [];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * Get id.
     *
     * @return int
     */
    public function getId() : int
    {
        return (int) $this->getKey();
    }

    /**
     * Get uuid.
     *
     * @return string
     */
    public function getUuid() : string
    {
        return $this->uuid;
    }

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
     * Apps relationship.
     * use distinct() to avoid duplicate apps.
     *
     * @return HasManyThrough
     */
    public function apps() : HasManyThrough
    {
        //return $this->hasMany(Companies::class, 'users_id');
        return $this->hasManyThrough(
            Apps::class,
            UsersAssociatedApps::class,
            'users_id',
            'id',
            'id',
            'apps_id'
        )->where('apps.is_deleted', StateEnums::NO->getValue())->distinct();
    }

    /**
     * Companies relationship.
     * use distinct() to avoid duplicate companies.
     *
     * @return HasManyThrough
     */
    public function companies() : HasManyThrough
    {
        //return $this->hasMany(Companies::class, 'users_id');
        return $this->hasManyThrough(
            Companies::class,
            UsersAssociatedCompanies::class,
            'users_id',
            'id',
            'id',
            'companies_id'
        )->where('companies.is_deleted', StateEnums::NO->getValue())->distinct();
    }

    /**
     * Get the current user information for the running app.
     *
     * @return UsersAssociatedApps
     */
    public function currentAppInfo() : UsersAssociatedApps
    {
        return UsersAssociatedApps::where('users_id', $this->getId())
            ->where('apps_id', app(Apps::class)->getKey())
            ->firstOrFail();
    }

    /**
     * CompaniesBranches relationship.
     *
     * @return HasManyThrough
     */
    public function branches() : HasManyThrough
    {
        return $this->hasManyThrough(
            CompaniesBranches::class,
            UsersAssociatedCompanies::class,
            'users_id',
            'id',
            'id',
            'companies_branches_id'
        )->where('companies_branches.is_deleted', StateEnums::NO->getValue());
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
     * notifications.
     *
     * @return HasMany
     */
    public function notifications() : HasMany
    {
        return $this->hasMany(Notifications::class, 'users_id')
            ->where('is_deleted', StateEnums::NO->getValue())
            ->where('apps_id', app(Apps::class)->getId());
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

    /**
     * What the current branch the users is logged in with.
     *
     * @return int
     */
    public function currentBranchId() : int
    {
        return  (int) $this->get($this->getCurrentCompany()->branchCacheKey());
    }

    /**
     * Get the current company in the user session.
     *
     * @return Companies
     */
    public function getCurrentCompany() : Companies
    {
        return CompaniesRepository::getById($this->currentCompanyId());
    }

    /**
     * unReadNotification.
     *
     * @return object
     */
    public function unReadNotification() : Collection
    {
        return $this->notifications()->where('read', 0)->get();
    }

    /**
     * Generate new forgot password hash.
     *
     * @return string
     */
    public function generateForgotHash() : string
    {
        $this->user_activation_forgot = Str::random(50);
        $this->updateOrFail();

        return $this->user_activation_forgot;
    }

    /**
     * Generate a hash password and updated for the user model.
     *
     * @param string $newPassword
     * @return bool
     */
    public function resetPassword(string $newPassword) : bool
    {
        $this->password = Hash::make($newPassword);
        $this->saveOrFail();

        $this->user_activation_forgot = '';
        $this->saveOrFail();

        return true;
    }
}
