<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * CompaniesGroups Model.
 *
 * @property int $apps_id
 * @property int $users_id
 * @property int $stripe_id
 * @property string $uuid
 * @property string $name
 * @property int $is_default
 * @property string $country_code
 */
class CompaniesGroups extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies_groups';

    protected $fillable = [
        'apps_id',
        'users_id',
        'name',
        'is_default',
        'country_code',
    ];

    /**
     * CompaniesBranches relationship.
     */
    public function companiesAssoc(): HasMany
    {
        return $this->hasMany(CompaniesAssociations::class, 'companies_groups_id');
    }

    /**
     * Companies relationship.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Companies::class, 'companies_associations');
    }

    /**
     * Users relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    /**
     * Associate Company to this group.
     *
     * @return void
     */
    public function associate(Companies $company, int $isDefault = 0)
    {
        $companyAssociations = new CompaniesAssociations();
        $companyAssociations->companies_id = $company->id;
        $companyAssociations->companies_groups_id = $this->id;
        $companyAssociations->is_default = $isDefault;
        $companyAssociations->saveOrFail();
    }
}
