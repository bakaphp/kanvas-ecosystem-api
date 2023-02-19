<?php

declare(strict_types=1);

namespace Kanvas\Locations\Countries\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Locations\Cities\Models\Cities;
use Kanvas\Locations\States\Models\States;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Countries Class.
 *
 * @property string $name
 * @property string $code
 * @property string $flag
 */

class Countries extends BaseModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'countries';

    protected $fillable = [
        'name',
        'code',
        'flag'
    ];

    /**
     * Cities relationship.
     *
     * @return hasMany
     */
    public function cities()
    {
        return $this->hasMany(Cities::class, 'countries_id');
    }

    /**
     * States relationship.
     *
     * @return hasMany
     */
    public function states()
    {
        return $this->hasMany(States::class, 'countries_id');
    }

    /**
     * Users relationship.
     *
     * @return HasMany
     */
    public function users(): HasMany
    {
        return $this->hasMany(Users::class, 'country_id');
    }
}
