<?php

declare(strict_types=1);

namespace Kanvas\Locations\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    use Cachable;
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'countries';

    protected $fillable = [
        'name',
        'code',
        'flag',
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
     */
    public function users(): HasMany
    {
        return $this->hasMany(Users::class, 'country_id');
    }
}
