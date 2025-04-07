<?php

declare(strict_types=1);

namespace Kanvas\Locations\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * States Class.
 *
 * @property int $countries_id
 * @property string $name
 * @property string $code
 */
class States extends BaseModel
{
    use Cachable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'countries_states';

    protected $fillable = [
        'countries_id',
        'name',
        'code',
    ];

    /**
     * Cities relationship.
     *
     * @return hasMany
     */
    public function cities()
    {
        return $this->hasMany(Cities::class, 'states_id');
    }

    /**
     * Countries relationship.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Countries::class, 'countries_id');
    }

    /**
     * Users relationship.
     */
    public function users(): HasMany
    {
        return $this->hasMany(Users::class, 'state_id');
    }
}
