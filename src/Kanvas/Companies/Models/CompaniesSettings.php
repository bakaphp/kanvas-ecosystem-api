<?php

declare(strict_types=1);

namespace Kanvas\Companies\Models;

use Baka\Casts\Json;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Models\BaseModel;

/**
 * CompaniesAssociations Model.
 *
 * @property int $companies_id
 * @property string $name
 * @property string $value
 */
class CompaniesSettings extends BaseModel
{
    use HasCompositePrimaryKeyTrait;

    protected $table = 'companies_settings';

    protected $primaryKey = ['companies_id', 'name'];

    protected $fillable = [
        'companies_id',
        'name',
        'value',
        'is_public',
        'is_deleted',
    ];

    protected $casts = [
        'value' => Json::class,
    ];

    /**
     * Companies relationship.
     */
    public function companies(): BelongsTo
    {
        return $this->company();
    }
}
