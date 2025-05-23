<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\AddressTraitRelationship;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HashTableTrait;
use Baka\Traits\SoftDeletesTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CompaniesTotalBranchesAction;
use Kanvas\Companies\Actions\SetUsersCountAction as CompaniesSetUsersCountAction;
use Kanvas\Companies\Enums\Defaults;
use Kanvas\Companies\Factories\CompaniesFactory;
use Kanvas\Companies\Observers\CompaniesObserver;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Exceptions\ModelNotFoundException as ExceptionsModelNotFoundException;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Repositories\FilesystemEntitiesRepository;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Models\BaseModel;
use Kanvas\Subscription\Subscriptions\Models\AppsStripeCustomer;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Override;

/**
 * Companies Model.
 *
 * @property int $users_id
 * @property int $system_modules_id
 * @property int $currency_id
 * @property string $uuid
 * @property string $name
 * @property string $profile_image
 * @property string $website
 * @property string $address
 * @property string $zipcode
 * @property string $email
 * @property string $language
 * @property string $timezone
 * @property string $phone
 * @property int $has_activities
 * @property string $country_code
 * @property bool $is_active
 */
#[ObservedBy([CompaniesObserver::class])]
class Companies extends BaseModel implements CompanyInterface
{
    use HashTableTrait;
    use HasFilesystemTrait;
    use CanUseWorkflow;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use CascadeSoftDeletes;
    use SoftDeletesTrait;
    use AddressTraitRelationship;

    protected $table = 'companies';

    protected $connection = 'ecosystem';

    protected $cascadeDeletes = ['branches'];

    public const DELETED_AT = 'is_deleted';

    protected $guarded = ['files', 'users_id', 'custom_fields'];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    #[Override]
    protected static function newFactory()
    {
        return CompaniesFactory::new();
    }

    /**
     * CompaniesBranches relationship.
     */
    #[Override]
    public function branches(): HasMany
    {
        return $this->hasMany(CompaniesBranches::class, 'companies_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CompaniesAddress::class, 'companies_id');
    }

    /**
     * Default Branch.
     * @psalm-suppress MixedReturnStatement
     */
    #[Override]
    public function defaultBranch(): HasOne
    {
        return $this->hasOne(
            CompaniesBranches::class,
            'companies_id'
        )->where('is_default', StateEnums::YES->getValue());
    }

    /**
     * CompaniesBranches relationship.
     */
    #[Override]
    public function branch(): HasOne
    {
        return $this->hasOne(CompaniesBranches::class, 'companies_id');
    }

    /**
     * CompaniesGroups relationship.
     */
    #[Override]
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CompaniesGroups::class, 'companies_associations');
    }

    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(
            Users::class,
            UsersAssociatedApps::class,
            'companies_id',
            'id',
            'id',
            'users_id'
        )->when(app(Apps::class), function ($query, $app) {
            $query->where('users_associated_apps.apps_id', $app->getId());
        });
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(
            IntegrationsCompany::class,
            'companies_id'
        );
    }

    #[Override]
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * SystemModules relationship.
     */
    public function systemModule(): BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }

    /**
     * Currencies relationship.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currencies::class, 'currency_id');
    }

    public function searchableAs(): string
    {
        return config('scout.prefix') . '_companies';
    }

    public function regions(): HasMany
    {
        return $this->hasMany(Regions::class, 'companies_id');
    }

    public function defaultRegion(): HasOne
    {
        return $this->hasOne(Regions::class, 'companies_id')->where('is_default', StateEnums::YES->getValue());
    }

    /**
     * Get the default company key for the current app
     * this is use to store in redis the default company id for the current
     * user in session every time they switch between companies on the diff apps.
     */
    public static function cacheKey(): string
    {
        return Defaults::DEFAULT_COMPANY_APP->getValue() . app(Apps::class)->id;
    }

    public static function searchableIndex(): string
    {
        return Defaults::SEARCHABLE_INDEX->getValue();
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    /**
     * Get the default company key for the current app
     * this is use to store in redis the default company id for the current
     * user in session every time they switch between companies on the diff apps.
     */
    public function branchCacheKey(): string
    {
        return Defaults::DEFAULT_COMPANY_BRANCH_APP->getValue() . app(Apps::class)->id . '_' . $this->getKey();
    }

    public function getTotalUsersAttribute(): int
    {
        return (int) ($this->get('total_users') ?? (new CompaniesSetUsersCountAction($this))->execute());
    }

    public function getTotalBranchesAttribute(): int
    {
        return (int) ($this->get('total_branches') ?? (new CompaniesTotalBranchesAction($this))->execute());
    }

    #[Override]
    public static function getById(mixed $id, ?AppInterface $app = null): self
    {
        try {
            return self::where('id', $id)
            ->notDeleted()
            ->firstOrFail();
        } catch (ModelNotFoundException $e) {
            //we want to expose the not found msg
            throw new ExceptionsModelNotFoundException($e->getMessage() . " $id");
        }
    }

    /**
     * Associate user to this company.
     * @psalm-suppress MixedReturnStatement
     */
    public function associateUser(
        Users $user,
        int|bool $isActive,
        CompaniesBranches $branch,
        ?int $userRoleId = null,
        ?string $companyUserIdentifier = null
    ): UsersAssociatedCompanies {
        return UsersAssociatedCompanies::firstOrCreate([
            'users_id' => $user->getKey(),
            'companies_id' => $this->getKey(),
            'companies_branches_id' => $branch->id,
        ], [
            'identify_id' => $companyUserIdentifier ?? $user->id,
            'user_active' => (int) $isActive,
            'user_role' => $userRoleId ?? $user->roles_id,
        ]);
    }

    /**
     * Associate user to the app.
     * @psalm-suppress MixedReturnStatement
     */
    public function associateUserApp(
        Users $user,
        Apps $app,
        int $isActive,
        ?int $userRoleId = null,
        ?string $password = null,
        ?string $companyUserIdentifier = null
    ): UsersAssociatedApps {
        return UsersAssociatedApps::firstOrCreate([
            'users_id' => $user->getKey(),
            'companies_id' => $this->getKey(),
            'apps_id' => $app->getKey(),
        ], [
            'identify_id' => $companyUserIdentifier ?? $user->id,
            'user_active' => $isActive,
            'user_role' => $userRoleId ?? $user->roles_id,
            'password' => $password,
        ]);
    }

    /**
     * Associate company to the app.
     * @psalm-suppress MixedReturnStatement
     */
    public function associateApp(Apps $app): UserCompanyApps
    {
        return UserCompanyApps::firstOrCreate([
            'apps_id' => $app->getId(),
            'companies_id' => $this->getId(),
        ]);
    }

    /**
     * Is this user the owner of this company?
     */
    public function isOwner(Users $user): bool
    {
        return $this->users_id === $user->getKey();
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Not deleted scope.
     */
    public function scopeUserAssociated(Builder $query): Builder
    {
        $user = Auth::user();
        $app = app(Apps::class);

        return $query->select('companies.*')
            ->join(
                'users_associated_apps',
                'users_associated_apps.companies_id',
                '=',
                'companies.id'
            )->when(! $user->isAdmin(), function ($query) use ($user) {
                $query->where('users_associated_apps.users_id', '=', $user->getKey());
            })
            ->where('users_associated_apps.is_deleted', '=', StateEnums::NO->getValue())
            ->where('users_associated_apps.apps_id', '=', $app->getKey())
            ->where('companies.is_deleted', '=', StateEnums::NO->getValue())
            ->groupBy('companies.id');
    }

    public function scopeCompanyInApp(Builder $query): Builder
    {
        $app = app(Apps::class);

        return $query->join(
            'user_company_apps',
            'user_company_apps.companies_id',
            '=',
            'companies.id'
        )->where('user_company_apps.apps_id', '=', $app->getId());
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->whereIn('apps', [app(Apps::class)->getId()]);
        if (! auth()->user()->isAdmin()) {
            $query->whereIn('users', [auth()->user()->getId()]);
        }

        return $query;
    }

    public function toSearchableArray()
    {
        $array = $this->toArray();
        $array['apps'] = UserCompanyApps::where('companies_id', $this->id)->get()->pluck('apps_id')->toArray();
        $array['users'] = CompaniesRepository::getAllCompanyUsers($this)->pluck('id')->toArray();
        $array = $this->transform($array);

        return $array;
    }

    public function getPhoto(): ?FilesystemEntities
    {
        $app = app(Apps::class);
        $defaultAvatarId = $app->get(AppSettingsEnums::DEFAULT_COMPANY_AVATAR->getValue());

        return $this->getFileByName('photo') ?: ($defaultAvatarId ? FilesystemEntitiesRepository::getFileFromEntityById($defaultAvatarId) : null);
    }

    public function getStripeAccount(AppInterface $app): AppsStripeCustomer
    {
        return AppsStripeCustomer::firstOrCreate([
            'companies_id' => $this->getId(),
            'apps_id' => $app->getId(),
        ]);
    }

    public function hasCompanyPermission(UserInterface $user): void
    {
        if (! UsersRepository::belongsToCompany($user, $this) && ! $user->isAdmin()) {
            throw new AuthorizationException(
                'You are not allowed to perform this action for company ' . $this->name
            );
        }
    }

    /**
     * The Typesense schema to be created for the Companies model.
     */
    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'users_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'system_modules_id',
                    'type' => 'int64',
                    'optional' => true,
                ],
                [
                    'name' => 'currency_id',
                    'type' => 'int64',
                    'optional' => true,
                ],
                [
                    'name' => 'uuid',
                    'type' => 'string',
                ],
                [
                    'name' => 'name',
                    'type' => 'string',
                    'sort' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'profile_image',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'website',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'address',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'zipcode',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'email',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'language',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'timezone',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'phone',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'country_code',
                    'type' => 'string',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'is_active',
                    'type' => 'bool',
                    'facet' => true,
                ],
                [
                    'name' => 'is_deleted',
                    'type' => 'bool',
                ],
                [
                    'name' => 'has_activities',
                    'type' => 'int64',
                    'optional' => true,
                ],
                [
                    'name' => 'apps',
                    'type' => 'int64[]',
                    'facet' => true,
                ],
                [
                    'name' => 'users',
                    'type' => 'int64[]',
                    'facet' => true,
                ],
                [
                    'name' => 'total_users',
                    'type' => 'int64',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'total_branches',
                    'type' => 'int64',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'created_at',
                    'type' => 'int64',
                ],
                [
                    'name' => 'updated_at',
                    'type' => 'int64',
                    'optional' => true,
                ],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,
        ];
    }
}
