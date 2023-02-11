<?php
declare(strict_types=1);

namespace Kanvas\Guild\Customers\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Models\BaseModel;
use Laravel\Scout\Searchable;

/**
 * Class Peoples.
 *
 * @property int $id
 * @property int $companies_id
 * @property int $apps_id
 * @property string $name
 * @property string|null $description
 *
 */
class PeoplesRelationships extends BaseModel
{
    use Searchable;

    protected $table = 'leads_participants_types';
    protected $guarded = [];

    public function company() : BelongsTo
    {
        return $this->belongsTo(
            Companies::class,
            'companies_id',
            'id'
        );
    }
}
