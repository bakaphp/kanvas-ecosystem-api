<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;

/**
 * CompaniesAssociations Model.
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
     * CompaniesGroups relationship.
     *
     */
    public function companiesGroups(): BelongsTo
    {
        return $this->belongsTo(CompaniesGroups::class, 'companies_groups_id');
    }

    /**
     * Companies relationship.
     *
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }
}
