<?php

declare(strict_types=1);

namespace Kanvas\Locations\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Cities Class.
 *
 * @property int $countries_id
 * @property int $states_id
 * @property string $name
 * @property float $latitude
 * @property float $longitude
 */
class Cities extends BaseModel
{
    use Cachable;

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
        'longitude',
    ];

    /**
     * Countries relationship.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Countries::class, 'countries_id');
    }

    /**
     * States relationship.
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(States::class, 'states_id');
    }

    /**
     * Users relationship.
     */
    public function users(): HasMany
    {
        return $this->hasMany(Users::class, 'city_id');
    }
}
