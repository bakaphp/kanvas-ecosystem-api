<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;

/**
 * CompaniesAssociations Model.
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
     * Companies relationship.
     *
     * @return Companies
     */
    public function companies() : BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }
}
