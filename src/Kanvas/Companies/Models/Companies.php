<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\Defaults;
use Kanvas\Companies\Factories\CompaniesFactory;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Enums\StateEnums;
use Kanvas\Models\BaseModel;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Traits\UsersAssociatedTrait;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;

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
 */
class Companies extends BaseModel implements CompanyInterface
{
    // use UsersAssociatedTrait;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies';

    /**
     * The attributes that should not be mass assignable.
     *
     * @var array
     */
    protected $guarded = ['files'];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return CompaniesFactory::new();
    }

    /**
     * CompaniesBranches relationship.
     *
     * @return hasMany
     */
    public function branches()
    {
        return $this->hasMany(CompaniesBranches::class, 'companies_id');
    }

    /**
     * Default Branch.
     *
     * @return HasOne
     */
    public function defaultBranch() : HasOne
    {
        return $this->hasOne(
            CompaniesBranches::class,
            'companies_id'
        )->where('is_default', StateEnums::YES->getValue());
    }

    /**
     * CompaniesBranches relationship.
     *
     * @return hasOne
     */
    public function branch()
    {
        return $this->hasOne(CompaniesBranches::class, 'companies_id');
    }

    /**
     * CompaniesGroups relationship.
     *
     * @return hasMany
     */
    public function groups()
    {
        return $this->belongsToMany(CompaniesGroups::class, 'companies_associations');
    }

    /**
     * Users relationship.
     *
     * @return Users
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * SystemModules relationship.
     *
     * @return SystemModules
     */
    public function systemModule() : BelongsTo
    {
        return $this->belongsTo(SystemModules::class, 'system_modules_id');
    }

    /**
     * Currencies relationship.
     *
     * @return Currencies
     */
    public function currency() : BelongsTo
    {
        return $this->belongsTo(Currencies::class, 'currency_id');
    }

    /**
     * Get the default company key for the current app
     * this is use to store in redis the default company id for the current
     * user in session every time they switch between companies on the diff apps.
     *
     * @return string
     */
    public static function cacheKey() : string
    {
        return Defaults::DEFAULT_COMPANY->getValue() . app(Apps::class)->id;
    }

    /**
     * Get the default company key for the current app
     * this is use to store in redis the default company id for the current
     * user in session every time they switch between companies on the diff apps.
     *
     * @return string
     */
    public function branchCacheKey() : string
    {
        return  Defaults::DEFAULT_COMPANY_BRANCH_APP->getValue() . app(Apps::class)->id . '_' . $this->getKey();
    }

    /**
     * Associate user to this company.
     *
     * @param Users $user
     * @param int $isActive
     * @param CompaniesBranches $branch
     * @param int|null $userRoleId
     * @param string|null $companyUserIdentifier
     *
     * @return UsersAssociatedCompanies
     */
    public function associateUser(
        Users $user,
        int $isActive,
        CompaniesBranches $branch,
        ?int $userRoleId = null,
        string $companyUserIdentifier = null
    ) : UsersAssociatedCompanies {
        return UsersAssociatedCompanies::firstOrCreate([
            'users_id' => $user->getKey(),
            'companies_id' => $this->getKey(),
            'companies_branches_id' => $branch->id,
        ], [
            'users_id' => $user->getKey(),
            'companies_id' => $this->getKey(),
            'companies_branches_id' => $branch->id,
            'identify_id' => $companyUserIdentifier ?? $user->id,
            'user_active' => $isActive,
            'user_role' => $userRoleId ?? $user->roles_id,
        ]);
    }

    /**
     * Associate user to the app.
     *
     * @param Users $user
     * @param Apps $app
     * @param int $isActive
     * @param int|null $userRoleId
     * @param string|null $password
     * @param string|null $companyUserIdentifier
     *
     * @return void
     */
    public function associateUserApp(
        Users $user,
        Apps $app,
        int $isActive,
        ?int $userRoleId = null,
        string $password = null,
        string $companyUserIdentifier = null
    ) : UsersAssociatedApps {
        return UsersAssociatedApps::firstOrCreate([
            'users_id' => $user->getKey(),
            'companies_id' => $this->getKey(),
            'apps_id' => $app->getKey(),
        ], [
            'users_id' => $user->getKey(),
            'companies_id' => $this->getKey(),
            'apps_id' => $app->getKey(),
            'identify_id' => $companyUserIdentifier ?? $user->id,
            'user_active' => $isActive,
            'user_role' => $userRoleId ?? $user->roles_id,
            'password' => $password
        ]);
    }

    /**
     * Is this user the owner of this company?
     *
     * @param Users $user
     *
     * @return bool
     */
    public function isOwner(Users $user) : bool
    {
        return $this->users_id === $user->getKey();
    }

    /**
     * Not deleted scope.
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeUserAssociated(Builder $query) : Builder
    {
        $user = Auth::user();
        return $query->join('users_associated_company', function ($join) use ($user) {
            $join->on('companies.id', '=', 'users_associated_company.companies_id')
                ->where('users_associated_company.users_id', '=', $user->getKey())
                ->where('users_associated_company.is_deleted', '=', 0);
        })
        ->where('companies.is_deleted', '=', 0);
    }
}
