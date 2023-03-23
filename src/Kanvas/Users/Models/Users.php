<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Traits\HashTableTrait;
use Baka\Traits\KanvasModelTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Locations\Models\Cities;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
use Kanvas\Notifications\Models\Notifications;
use Kanvas\Roles\Models\Roles;
use Kanvas\Users\Factories\UsersFactory;
use Silber\Bouncer\Database\HasRolesAndAbilities;

/**
 * Users Model.
 *
 * @property string $uuid
 * @property string $email
 * @property string $password
 * @property string $firstname
 * @property string $lastname
 * @property string $description
 * @property int    $roles_id
 * @property string $displayname
 * @property int    $default_company
 * @property int    $default_company_branch
 * @property int    $city_id
 * @property int    $state_id
 * @property int    $country_id
 * @property string $registered
 * @property string $lastvisit
 * @property string $sex
 * @property string $dob
 * @property string $timezone
 * @property string $phone_number
 * @property string $cell_phone_number
 * @property int    $profile_privacy
 * @property string $profile_image
 * @property string $profile_header
 * @property string $profile_header_mobile
 * @property int    $user_active
 * @property int    $user_login_tries
 * @property int    $user_last_login_try
 * @property int    $session_time
 * @property int    $session_page
 * @property int    $welcome
 * @property string $user_activation_key
 * @property string $user_activation_email
 * @property string $user_activation_forgot
 * @property string $language
 * @property int    $karma
 * @property int    $votes
 * @property int    $votes_points
 * @property int    $banned
 * @property string $location
 * @property int    $system_modules_id
 * @property int    $status
 * @property string $address_1
 * @property string $address_2
 * @property string $zip_code
 * @property int    $user_recover_code
 * @property int    $is_deleted
 */
class Users extends Authenticatable implements UserInterface, ContractsAuthenticatable
{
    use HashTableTrait;
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
     */
    public function getId(): int
    {
        return (int) $this->getKey();
    }

    /**
     * Get uuid.
     */
    public function getUuid(): string
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
     */
    public function defaultCompany(): HasOne
    {
        return $this->hasOne(Companies::class, 'id', 'default_company');
    }

    /**
     * Apps relationship.
     * use distinct() to avoid duplicate apps.
     */
    public function apps(): HasManyThrough
    {
        // return $this->hasMany(Companies::class, 'users_id');
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
     */
    public function companies(): HasManyThrough
    {
        // return $this->hasMany(Companies::class, 'users_id');
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
     * User city relationship.
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(Cities::class, 'city_id');
    }

    /**
     * User state relationship.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(States::class, 'state_id');
    }

    /**
     * User country relationship.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Countries::class, 'country_id');
    }

    /**
     * Get the current user information for the running app.
     */
    public function currentAppInfo(): UsersAssociatedApps
    {
        return UsersAssociatedApps::where('users_id', $this->getId())
            ->where('apps_id', app(Apps::class)->getKey())
            ->firstOrFail();
    }

    /**
     * CompaniesBranches relationship.
     */
    public function branches(): HasManyThrough
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
     */
    public function role(): HasOne
    {
        return $this->hasOne(Roles::class, 'id', 'roles_id');
    }

    /**
     * notifications.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notifications::class, 'users_id')
            ->where('is_deleted', StateEnums::NO->getValue())
            ->where('apps_id', app(Apps::class)->getId());
    }

    /**
     * User linked sources.
     *
     * @return HasMany
     */
    public function linkedSources(): HasMany
    {
        return $this->hasMany(UserLinkedSources::class, 'users_id');
    }

    /**
     * Get User's email.
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Get user by there email address.
     *
     * @param string $email
     * @return self
     */
    public static function getByEmail(string $email): self
    {
        $user = self::notDeleted()
            ->where(
                [
                    'email' => $email,
                    'is_deleted' => 0,
                ]
            )->first();

        if (! $user) {
            throw new ModelNotFoundException('No User Found');
        }

        return $user;
    }

    /**
     * is the user active?
     */
    public function isActive(): bool
    {
        return (bool) $this->user_active;
    }

    /**
     * Determine if a user is banned.
     */
    public function isBanned(): bool
    {
        return ! $this->isActive() && $this->banned === 'Y';
    }

    /**
     * Set hashtable settings table, userConfig ;).
     */
    protected function createSettingsModel(): void
    {
        $this->settingsModel = new UserConfig();
    }

    /**
     * A company owner is the first person that register this company
     * This only ocurred when signing up the first time, after that all users invites
     * come with a default_company id attached.
     */
    public function isFirstSignup(): bool
    {
        return empty($this->default_company);
    }

    /**
     * What the current company the users is logged in with
     * in this current session?
     */
    public function currentCompanyId(): int
    {
        if (! app()->bound(CompaniesBranches::class)) {
            $currentCompanyId = $this->get(Companies::cacheKey());
        } else {
            $currentCompanyId = app(CompaniesBranches::class)->company()->first()->getId();
        }

        return $currentCompanyId ? (int) $currentCompanyId : $this->default_company;
    }

    /**
     * What the current branch the users is logged in with.
     */
    public function currentBranchId(): int
    {
        if (! app()->bound(CompaniesBranches::class)) {
            return (int) $this->get($this->getCurrentCompany()->branchCacheKey());
        } else {
            return app(CompaniesBranches::class)->company()->first()->getId();
        }
    }

    /**
     * Get the current company in the user session.
     */
    public function getCurrentCompany(): CompanyInterface
    {
        try {
            return Companies::getById($this->currentCompanyId());
        } catch (EloquentModelNotFoundException $e) {
            throw new InternalServerErrorException(
                'No default company app configured for this user on 
                the current app ' . app(Apps::class)->name . ', 
                please contact support'
            );
        }
    }

    /**
     * Get the current company in the user session.
     */
    public function getCurrentBranch(): CompaniesBranches
    {
        try {
            return CompaniesBranches::getById($this->currentBranchId());
        } catch (EloquentModelNotFoundException $e) {
            throw new InternalServerErrorException(
                'No default company app configured 
                for this user on the current app ' . app(Apps::class)->name . ', 
                please contact support'
            );
        }
    }

    /**
     * unReadNotification.
     */
    public function unReadNotification(): Collection
    {
        return $this->notifications()->where('read', 0)->get();
    }

    /**
     * Generate new forgot password hash.
     */
    public function generateForgotHash(): string
    {
        $this->user_activation_forgot = Str::random(50);
        $this->updateOrFail();

        return $this->user_activation_forgot;
    }

    /**
     * Generate a hash password and updated for the user model.
     */
    public function resetPassword(string $newPassword): bool
    {
        $this->password = Hash::make($newPassword);
        $this->saveOrFail();

        $this->user_activation_forgot = '';
        $this->saveOrFail();

        return true;
    }

    /**
     * Is the creator of the current app.
     */
    public function isAppOwner(): bool
    {
        return $this->getId() === app(Apps::class)->users_id;
    }
}
