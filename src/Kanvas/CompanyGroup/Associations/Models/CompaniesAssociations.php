<?php

declare(strict_types=1);

namespace Kanvas\CompanyGroup\Associations\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Kanvas\CompanyGroup\Groups\Models\CompaniesGroups;
use Kanvas\Companies\Models\Companies;

/**
 * CompaniesAssociations Model
 *
 * @property int $companies_groups_id
 * @property int $companies_id
 * @property int $is_default
 *
 */
class CompaniesAssociations extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies_associations';

    /**
     * CompaniesGroups relationship
     *
     * @return CompaniesGroups
     */
    public function companiesGroups(): CompaniesGroups
    {
        return $this->belongsTo(CompaniesGroups::class, 'companies_groups_id');
    }

    /**
     * Companies relationship
     *
     * @return Companies
     */
    public function companies(): Companies
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }
}
