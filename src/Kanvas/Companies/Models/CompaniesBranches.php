<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Kanvas\Companies\Branches\Factories\CompaniesBranchesFactory;
use Kanvas\Filesystem\Models\FilesystemEntities;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

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

    /**
     * Filter what the user can see.
     */
    public function scopeUserAssociated(Builder $query): Builder
    {
        $user = Auth::user();

        return $query->join('users_associated_company', function ($join) use ($user) {
            $join->on('users_associated_company.companies_id', '=', 'companies_branches.companies_id')
                ->where('users_associated_company.users_id', '=', $user->getKey())
                ->where('users_associated_company.is_deleted', '=', 0);
        })
        ->where('companies_branches.is_deleted', '=', 0);
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
        return  $this->getFileByName('photo');
    }
}
