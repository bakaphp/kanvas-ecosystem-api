<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Branches\Factories\CompaniesBranchesFactory;
use Kanvas\Companies\Enums\Defaults;
use Kanvas\CustomFields\Traits\HasCustomFields;
use Kanvas\Enums\StateEnums;
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
 */
class CompaniesBranches extends BaseModel
{
    use UuidTrait;
    use HasFilesystemTrait;
    use Searchable;
    use HasCustomFields;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies_branches';

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

        return $this->get('total_users');
    }

    public function shouldBeSearchable(): bool
    {
        return $this->is_deleted === StateEnums::NO->getValue();
    }

    /**
     * Filter what the user can see.
     */
    public function scopeUserAssociated(Builder $query): Builder
    {
        $user = Auth::user();

        return $query->join('users_associated_company', function ($join) use ($user) {
            $join->on('users_associated_company.companies_id', '=', 'companies_branches.companies_id')
                ->where('users_associated_company.is_deleted', '=', 0);
        })
        ->join('users_associated_apps', function ($join) {
            $join->on('users_associated_apps.companies_id', '=', 'companies_branches.companies_id')
                ->where('users_associated_apps.apps_id', app(Apps::class)->getId());
        })
        ->when(! $user->isAdmin(), function ($query) use ($user) {
            $query->where('users_associated_company.users_id', $user->getId());
        })
        ->where('companies_branches.is_deleted', '=', 0);
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

    public function getPhoto(): ?FilesystemEntities
    {
        return $this->getFileByName('photo');
    }
}
