<?php

declare(strict_types=1);
namespace Kanvas\Locations\States\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Locations\Countries\Models\Countries;
use Kanvas\Locations\Cities\Models\Cities;

/**
 * Cities Class
 *
 * @property int $countries_id
 * @property string $name
 * @property string $code
 */

class States extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'countries_states';

    protected $fillable = [
        'countries_id',
        'name',
        'code'
    ];

    /**
     * Cities relationship
     *
     * @return hasMany
     */
    public function cities()
    {
        return $this->hasMany(Cities::class, 'states_id');
    }

    /**
     * Countries relationship
     *
     * @return Countries
     */
    public function country(): Countries
    {
        return $this->belongsTo(Countries::class, 'countries_id');
    }
}
