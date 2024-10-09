<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Traits\HashTableTrait;
use Baka\Traits\KanvasModelTrait;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException as EloquentModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Enums\DefaultRoles;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Auth\Contracts\Authenticatable as ContractsAuthenticatable;
use Kanvas\Auth\Traits\HasApiTokens;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Locations\Models\Cities;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
use Kanvas\Notifications\Models\Notifications;
use Kanvas\Notifications\Traits\HasNotificationSettings;
use Kanvas\Roles\Models\Roles;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Interactions\Traits\LikableTrait;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Enums\UserConfigEnum;
use Kanvas\Users\Factories\UsersFactory;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Laravel\Scout\Searchable;
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
    use LikableTrait;
    use HasFilesystemTrait;
    use KanvasModelTrait;
    use HasNotificationSettings;
    use Searchable {
        search as public traitSearch;
    }

    use CanUseWorkflow;

    protected ?string $defaultCompanyName = null;
    protected ?string $currentDeviceId = null;

    protected $guarded = [];

    protected $casts = [
        'default_company' => 'integer',
        'default_company_branch' => 'integer',
        'welcome' => 'boolean',
    ];

    protected $hidden = [
        'password',
        'user_activation_key',
        'user_activation_email',
        'user_activation_forgot',
    ];

    protected $fillable = [
        'email',
        'password',
        'firstname',
        'lastname',
        'description',
        'roles_id',
        'displayname',
        'default_company',
        'default_company_branch',
        'city_id',
        'state_id',
        'country_id',
        'registered',
        'lastvisit',
        'sex',
        'dob',
        'timezone',
        'phone_number',
        'cell_phone_number',
        'profile_privacy',
        'profile_image',
        'profile_header',
        'profile_header_mobile',
        'user_active',
        'user_login_tries',
        'user_last_login_try',
        'welcome',
        'user_activation_key',
        'user_activation_email',
        'user_activation_forgot',
        'language',
        'karma',
        'votes',
        'votes_points',
        'banned',
        'location',
        'status',
        'address_1',
        'address_2',
        'zip_code',
        'user_recover_code',
    ];

    protected $connection = 'mysql';

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
     * Apps relationship.
     * use distinct() to avoid duplicate apps.
     * @psalm-suppress MixedReturnStatement
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

    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'apps_id', 'apps_id')->where('model_name', self::class);
    }

    /**
     * Companies relationship.
     * use distinct() to avoid duplicate companies.
     * @psalm-suppress MixedReturnStatement
     */
    public function companies(): HasManyThrough
    {
        // return $this->hasMany(Companies::class, 'users_id');
        return $this->hasManyThrough(
            Companies::class,
            UsersAssociatedApps::class,
            'users_id',
            'id',
            'id',
            'companies_id'
        )->where('users_associated_apps.apps_id', app(Apps::class)->getId())
        ->where('companies.is_deleted', StateEnums::NO->getValue())->distinct();
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

    public function getMainRoleAttribute(): string
    {
        $role = Roles::where('scope', RolesEnums::getScope(app(Apps::class)))->first();

        return $role ? $role->name : '';
    }

    /**
     * Get the current user information for the running app.
     * @psalm-suppress MixedReturnStatement
     */
    public function getAppProfile(AppInterface $app): UsersAssociatedApps
    {
        try {
            return UsersAssociatedApps::where('users_id', $this->getId())
                ->where('apps_id', $app->getId())
                ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->firstOrFail();
        } catch (EloquentModelNotFoundException $e) {
            /**
             * until v3 (legacy) is deprecated we have to check or create the user profile the first time
             * @todo remove in v2
             */
            try {
                UsersRepository::belongsToThisApp($this, $app);
            } catch (ModelNotFoundException $e) {
                throw new ModelNotFoundException('User not found in app - ' . $this->getId());
            }
            $userRegisterInApp = new RegisterUsersAppAction($this);
            $userRegisterInApp->execute($this->password);

            throw new ModelNotFoundException('User not found - ' . $this->getId());
        }
    }

    /**
     * Get the current user information for the running app.
     * @psalm-suppress MixedReturnStatement
     */
    public function getCompanyProfile(AppInterface $app, CompanyInterface $company): UsersAssociatedApps
    {
        try {
            return UsersAssociatedApps::where('users_id', $this->getId())
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->firstOrFail();
        } catch (EloquentModelNotFoundException $e) {
            throw new ModelNotFoundException('User not found in this company');
        }
    }

    /**
     * CompaniesBranches relationship.
     * @psalm-suppress MixedReturnStatement
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
     * @psalm-suppress MixedReturnStatement
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notifications::class, 'users_id')
            ->where('is_deleted', StateEnums::NO->getValue())
            ->where('apps_id', app(Apps::class)->getId());
    }

    /**
     * User linked sources.
     */
    public function linkedSources(): HasMany
    {
        return $this->hasMany(UserLinkedSources::class, 'users_id');
    }

    public function channels(): BelongsToMany
    {
        $databaseSocial = config('database.social.database', 'social');

        return $this->belongsToMany(Channel::class, $databaseSocial . '.channel_users', 'users_id', 'channel_id');
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

    public function defaultCompany(): int
    {
        return $this->currentCompanyId();
    }

    public function defaultCompanyBranch(): int
    {
        return $this->currentBranchId();
    }

    public function defaultCompanyUuid(): string
    {
        return Companies::getById($this->currentCompanyId())->uuid;
    }

    public function defaultCompanyBranchUuid(): string
    {
        return CompaniesBranches::getById($this->currentBranchId())->uuid;
    }

    /**
     * What the current company the users is logged in with
     * in this current session?
     * @psalm-suppress MixedReturnStatement
     */
    public function currentCompanyId(): int
    {
        if (! app()->bound(CompaniesBranches::class)) {
            $currentCompanyId = $this->get(Companies::cacheKey());
        } else {
            //verify I have access to it
            $currentCompanyId = app(CompaniesBranches::class)->company()->first()->getId();
        }

        return $currentCompanyId ? (int) $currentCompanyId : $this->default_company;
    }

    /**
     * What the current branch the users is logged in with.
     * @psalm-suppress MixedReturnStatement
     */
    public function currentBranchId(): int
    {
        if (! app()->bound(CompaniesBranches::class)) {
            $currentBranchId = (int) $this->get($this->getCurrentCompany()->branchCacheKey());
        } else {
            $currentBranchId = app(CompaniesBranches::class)->getId();
        }

        return $currentBranchId ? (int) $currentBranchId : $this->default_company_branch;
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
    public function generateForgotHash(AppInterface $app): string
    {
        $user = $this->getAppProfile($app);
        $user->user_activation_forgot = Str::random(50);
        $user->updateOrFail();

        return $user->user_activation_forgot;
    }

    public function changePassword(string $currentPassword, string $newPassword, AppInterface $app): bool
    {
        $user = $this->getAppProfile($app);

        if (! Hash::check($currentPassword, (string) $user->password)) {
            throw new InternalServerErrorException('Current password is incorrect');
        }

        return $this->resetPassword($newPassword, $app);
    }

    /**
     * Generate a hash password and updated for the user model.
     */
    public function resetPassword(string $newPassword, AppInterface $app): bool
    {
        $user = $this->getAppProfile($app);
        $user->password = Hash::make($newPassword);
        $user->user_activation_forgot = '';

        $this->fireWorkflow(
            WorkflowEnum::AFTER_FORGOT_PASSWORD->value,
            true,
            [
                'app' => $app,
                'profile' => $user,
            ]
        );

        return $user->saveOrFail();
    }

    public function updateDisplayName(string $displayName, AppInterface $app): bool
    {
        $user = $this->getAppProfile($app);

        $validator = Validator::make(
            ['displayname' => $displayName],
            ['displayname' => 'required|unique:users_associated_apps,displayname,NULL,users_id,apps_id,' . $app->getId()]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $user->displayname = $displayName;

        /**
         * @todo, we will udpate legacy user displayname until we migrate them over to graph
         */
        $this->displayname = $displayName;
        $this->saveOrFail();

        return $user->updateOrFail();
    }

    public function updateEmail(string $email, AppInterface $app): bool
    {
        //@todo in the future we should remove this validation and use only the one in the app
        $validator = Validator::make(
            ['email' => $email],
            ['email' => 'required|email|unique:users,email,' . $this->id]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $user = $this->getAppProfile($app);

        $validator = Validator::make(
            ['email' => $email],
            [
                'email' => [
                    'required',
                    Rule::unique('users_associated_apps')->ignore($this->id, 'users_id')
                        ->where(function ($query) use ($app) {
                            return $query->where('apps_id', $app->getId());
                        }),
                ],
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $this->email = $email;
        $user->email = $email;

        $user->updateOrFail();

        return $this->saveOrFail();
    }

    /**
     * Is the owner of the current app.
     *  @psalm-suppress MixedReturnStatement
     */
    public function isAppOwner(): bool
    {
        if (app()->bound(AppKey::class) && $this->isAn(RolesEnums::OWNER->value)) {
            return true;
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->isAppOwner() || $this->isAn(RolesEnums::ADMIN->value) || $this->isAn(RolesEnums::OWNER->value);
    }

    /**
     * list of abilities name for this user.
     */
    public function getAbilitiesList(): array
    {
        /**
         * @psalm-suppress InvalidTemplateParam
         */
        $mapAbilities = $this->getAbilities()->map(function ($ability) {
            return $ability->name;
        });

        if ($this->isAn((string) DefaultRoles::ADMIN->getValue())) {
            $mapAbilities->prepend('*');
        }

        return $mapAbilities->all();
    }

    public function getAppDisplayName(): string
    {
        $user = $this->getAppProfile(app(Apps::class));

        return $user->displayname ?? $this->displayname;
    }

    public function getAppEmail(): string
    {
        $user = $this->getAppProfile(app(Apps::class));

        return ! empty($user->email) ? $user->email : $this->email;
    }

    public function getAppIsActive(): bool
    {
        $user = $this->getAppProfile(app(Apps::class));

        return (bool) $user->is_active;
    }

    public function getAppWelcome(): bool
    {
        $user = $this->getAppProfile(app(Apps::class));

        return (bool) $user->welcome;
    }

    public function runVerifyTwoFactorAuth(?AppInterface $app = null): bool
    {
        $user = $this->getAppProfile($app ?? app(Apps::class));
        $twoFactorKey = $this->getCurrentDeviceId() ? UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value . '-' . $this->getCurrentDeviceId() : UserConfigEnum::TWO_FACTOR_AUTH_30_DAYS->value;

        if (! $this->get($twoFactorKey) && $user->phone_verified_at && now()->subDays(7)->lte(new Carbon($user->phone_verified_at))) {
            return false;
        }

        /**
         * @todo user config per app
         */
        return ! ((bool) $this->get($twoFactorKey)
                && $user->phone_verified_at && now()->subDays(30)->lte(new Carbon($user->phone_verified_at)));
    }

    public function getPhoto(): ?FilesystemEntities
    {
        $app = app(Apps::class);
        $defaultAvatarId = $app->get(AppSettingsEnums::DEFAULT_USER_AVATAR->getValue());

        return $this->getFileByName('photo') ?: ($defaultAvatarId ? FilesystemEntitiesRepository::getFileFromEntityById($defaultAvatarId) : null);
    }

    public function getSocialInfo(): array
    {
        return [
            'total_message' => Message::fromApp(app(Apps::class))->where('users_id', $this->getId())->count(),
            'total_like' => 0,
            'total_followers' => 0,
            'total_following' => 0,
            'total_list' => 0,
        ];
    }

    public static function getByIdFromCompany(mixed $id, CompanyInterface $company): self
    {
        try {
            return self::where('id', $id)
                ->whereRelation('companies', 'id', $company->getId())
                ->notDeleted()
                ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage());
        }
    }

    public static function searchableIndex(): string
    {
        return 'users_index_';
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted() && $this->isActive() && $this->banned == 0;
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->getId(),
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'displayname' => $this->displayname,
            'email' => $this->email,
            'apps' => $this->apps->pluck('id')->toArray(),
            'companies' => $this->companies->pluck('id')->toArray(),
        ];
    }

    public function searchableAs(): string
    {
        return config('scout.prefix') . '_users';
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->whereIn('apps', [app(Apps::class)->getId()]);
        if (! auth()->user()->isAdmin()) {
            $query->whereIn('companies', [auth()->user()->currentCompanyId()]);
        }

        return $query;
    }

    public function setCurrentDeviceId(?string $deviceId = null): void
    {
        $this->currentDeviceId = $deviceId;
    }

    public function getCurrentDeviceId(): ?string
    {
        return $this->currentDeviceId;
    }
}
