<?php

declare(strict_types=1);
namespace Kanvas\Locations\Cities\Models;

use Kanvas\Models\BaseModel;
use Kanvas\Locations\Countries\Models\Countries;
use Kanvas\Locations\States\Models\States;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cities Class
 *
 * @property int $countries_id
 * @property int $states_id
 * @property string $name
 * @property float $latitude
 * @property float $longitude
 */

class Cities extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'countries_cities';

    protected $fillable = [
        'countries_id',
        'states_id',
        'name',
        'latitude',
        'longitude'
    ];

    /**
     * Countries relationship
     *
     * @return Countries
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Countries::class, 'countries_id');
    }

    /**
     * States relationship
     *
     * @return States
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(States::class, 'states_id');
    }
}
