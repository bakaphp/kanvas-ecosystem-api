<?php

declare(strict_types=1);

namespace Kanvas\CompanyGroup\Settings\Models;

use Kanvas\Models\BaseModel;
use Kanvas\CompanyGroup\Companies\Models\Companies;

/**
 * CompaniesAssociations Model
 *
 * @property int $companies_id
 * @property string $name
 * @property string $value
 *
 */
class CompaniesSettings extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies_settings';

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
