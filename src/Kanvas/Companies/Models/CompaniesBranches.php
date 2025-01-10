<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Baka\Traits\AddressTraitRelationship;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Branches\Factories\CompaniesBranchesFactory;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedCompanies;
use Laravel\Scout\Searchable;

/**
 * Companies Model.
 *
 * @property int $companies_id
 * @property int $users_id
 * @property string $name
 * @property string $address
 * @property string $email
 * @property string $phone
 * @property string $zipcode
 * @property int $is_default
 * @property int $is_active
 */
class CompaniesBranches extends BaseModel
{
    use UuidTrait;
    use HasFilesystemTrait;
    use Searchable;
    use NoAppRelationshipTrait;
    use HasCustomFields;
    use AddressTraitRelationship;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies_branches';

    protected $guarded = ['email', 'users_id', 'companies_id'];

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return CompaniesBranchesFactory::new();
    }

    /**
     * Companies relationship.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    /**
     * Users relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    public function getPhoto(): ?FilesystemEntities
    {
        return $this->getFileByName('photo');
    }

    /**
     * Is default?
     */
    public function isDefault(): bool
    {
        return (bool) $this->is_default;
    }

    public function getTotalUsersAttribute(): int
    {
        if (! $this->get('total_users')) {
            $this->set('total_users', $this->users()->count());
        }

        return (int) $this->get('total_users');
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    /**
     * Filter what the user can see.
     */
    public function scopeUserAssociated(Builder $query): Builder
    {
        $user = Auth::user();
        $appId = app(Apps::class)->getId();
        $companyBranch = app()->bound(CompaniesBranches::class)
            ? app(CompaniesBranches::class)->company()->first()
            : null;

        return $query
            ->select('companies_branches.*') // Explicitly select all columns from companies_branches
            ->where('companies_branches.is_deleted', 0) // Filter out deleted branches early
            ->whereExists(function ($subQuery) use ($appId) {
                $subQuery
                    ->from('users_associated_company')
                    ->whereColumn('users_associated_company.companies_id', 'companies_branches.companies_id')
                    ->where('users_associated_company.is_deleted', 0);
            })
            ->whereExists(function ($subQuery) use ($appId) {
                $subQuery
                    ->from('users_associated_apps')
                    ->whereColumn('users_associated_apps.companies_id', 'companies_branches.companies_id')
                    ->where('users_associated_apps.apps_id', $appId);
            })
            ->when(
                ! $user->isAdmin(),
                function ($query) use ($user) {
                    $query->whereExists(function ($subQuery) use ($user) {
                        $subQuery
                            ->from('users_associated_company')
                            ->whereColumn('users_associated_company.companies_id', 'companies_branches.companies_id')
                            ->where('users_associated_company.users_id', $user->getId());
                    });
                }
            )
            ->when(
                $companyBranch,
                function ($query) use ($companyBranch) {
                    $query->where('companies_branches.companies_id', $companyBranch->getId());
                }
            );
    }

    public function users(): HasManyThrough
    {
        return $this->hasManyThrough(
            Users::class,
            UsersAssociatedCompanies::class,
            'companies_branches_id',
            'id',
            'id',
            'users_id'
        );
    }

    /**
     * Get a branch with id 0 , representing the global branch.
     */
    public static function getGlobalBranch(): self
    {
        $branch = new self();
        $branch->id = 0;

        return $branch;
    }
}
